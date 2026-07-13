<?php

namespace App\Services\SubcultureGameInfo\Agent;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\EventChallenge;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Services\SubcultureGameInfo\Raids\AttributePartyService;
use App\Services\SubcultureGameInfo\Raids\CrawlerScriptRunner;
use App\Services\SubcultureGameInfo\Raids\RaidQueryService;
use App\Services\SubcultureGameInfo\RedeemCodeService;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\DcGuidePostDriver;
use Prism\Prism\Facades\Tool;

/**
 * 에이전트가 호출하는 도구(function calling) 모음.
 *
 * 각 도구는 ① LLM 에게는 토큰 효율적 요약 텍스트를 반환하고
 * ② 프론트 렌더용 구조화 카드를 $cards 에 누적한다(툴 호출 기록도 $toolCalls 에).
 * 대부분 이미 매일 크론으로 DB 에 적재된 데이터라 즉답이며, 실시간 크롤 도구는 LiveTools 로 분리.
 */
class AgentTools
{
    use FetchesWebContent;

    /** @var array<int, array{type: string, data: array}> 프론트 렌더용 카드 */
    public array $cards = [];

    /** @var array<int, array{name: string, label: string}> 진행 표시·감사용 */
    public array $toolCalls = [];

    public function __construct(
        private RedeemCodeService $codes,
        private RaidQueryService $raids,
        private AttributePartyService $attributeParties,
        private DcGuidePostDriver $dc,
        private ArcaGuidePostDriver $arca,
        private CrawlerScriptRunner $browser,
    ) {}

    /** @return list<\Prism\Prism\Tool> */
    public function all(): array
    {
        return [
            $this->redeemCodesTool(),
            $this->raidsTool(),
            $this->charactersTool(),
            $this->eventChallengesTool(),
            $this->guidePostsTool(),
            $this->attributePartiesTool(),
            $this->communitySearchTool(),
            $this->livePageTool(),
        ];
    }

    private function redeemCodesTool(): \Prism\Prism\Tool
    {
        return Tool::as('search_redeem_codes')
            ->for('게임의 사용 가능한 리딤/쿠폰 코드를 조회한다. game 을 비우면 전체 게임.')
            ->withStringParameter('game', '게임 이름(예: 블루아카이브, 명조, 원신). 전체면 빈 문자열', false)
            ->using(function (string $game = '') {
                $slug = $this->resolveGame($game);
                $this->track('search_redeem_codes', '리딤코드 검색'.($slug ? " · {$this->gameName($slug)}" : ''));

                $groups = $this->codes->grouped($slug);
                $items = [];
                $lines = [];
                foreach ($groups as $g) {
                    foreach ($g['verified'] as $code) {
                        $items[] = ['game' => $g['game']->name, 'code' => $code->code, 'reward' => $code->rewards, 'status' => $code->status?->value];
                        $lines[] = "{$g['game']->name}: {$code->code} ({$code->rewards})";
                    }
                }
                if ($items === []) {
                    return '현재 수집된 사용 가능 리딤코드가 없습니다.';
                }
                $this->card('redeem_codes', ['game' => $slug ? $this->gameName($slug) : '전체', 'items' => array_slice($items, 0, 40)]);

                return '리딤코드 '.count($items)."개:\n".implode("\n", array_slice($lines, 0, 40));
            });
    }

    private function raidsTool(): \Prism\Prism\Tool
    {
        return Tool::as('get_raids')
            ->for('레이드/보스 일정과 추천 편성을 조회한다. status 는 active(진행중)/upcoming(예정)/ended(종료).')
            ->withStringParameter('game', '게임 이름(블루아카이브/니케/트릭컬/브라운더스트2). 전체면 빈 문자열', false)
            ->withStringParameter('status', 'active|upcoming|ended 중 하나. 비우면 전체', false)
            ->using(function (string $game = '', string $status = '') {
                $slug = $this->resolveGame($game);
                $this->track('get_raids', '레이드 조회'.($slug ? " · {$this->gameName($slug)}" : ''));

                $raids = $this->raids->listRaids($slug ?: null, $status ?: null);
                if ($raids->isEmpty()) {
                    return '해당 조건의 레이드가 없습니다.';
                }

                $summaries = $raids->take(12);
                $cardItems = $summaries->map(fn ($r) => [
                    'id' => $r['id'] ?? null,
                    'game' => $r['game']['name'] ?? null,
                    'name' => $r['name'],
                    'boss' => $r['boss_name'] ?? null,
                    'status' => $r['status'] ?? null,
                    'starts_at' => $r['starts_at'] ?? null,
                    'ends_at' => $r['ends_at'] ?? null,
                    'parties' => $r['parties_count'] ?? 0,
                ])->all();

                // 결과가 좁혀졌으면(3건 이하) 추천 편성 멤버까지 상세 제공 — 재호출 없이 한 번에 답할 수 있게.
                $lines = [];
                if ($summaries->count() <= 3) {
                    foreach ($summaries as $i => $summary) {
                        $raid = \App\Models\SubcultureGameInfo\Raid::find($summary['id'] ?? 0);
                        if ($raid === null) {
                            continue;
                        }
                        $detail = $this->raids->showRaid($raid);
                        $partyLines = collect($detail['parties'] ?? [])->take(6)->map(function ($p) {
                            $members = collect($p['members'] ?? [])
                                ->map(fn ($m) => $m['character']['name'] ?? null)->filter()->implode(', ');

                            return "  - {$p['title']}: {$members}";
                        });
                        $lines[] = "{$summary['name']} [{$summary['status']}] {$summary['starts_at']}~{$summary['ends_at']}";
                        $lines[] = $partyLines->isEmpty() ? '  (수집된 추천 편성 없음 — 공략글 참고)' : $partyLines->implode("\n");
                        $cardItems[$i]['party_details'] = collect($detail['parties'] ?? [])->take(6)->map(fn ($p) => [
                            'title' => $p['title'],
                            'note' => $p['note'] ?? null,
                            'members' => collect($p['members'] ?? [])->map(fn ($m) => [
                                'name' => $m['character']['name'] ?? null,
                                'image_url' => $m['character']['image_url'] ?? null,
                            ])->filter(fn ($m) => $m['name'])->values()->all(),
                        ])->all();
                    }
                } else {
                    $lines[] = collect($cardItems)->map(fn ($r) => "{$r['name']} [{$r['status']}] 편성 {$r['parties']}개")->implode("\n");
                }
                $this->card('raids', ['items' => $cardItems]);

                return '레이드 '.count($cardItems)."건:\n".implode("\n", $lines);
            });
    }

    private function charactersTool(): \Prism\Prism\Tool
    {
        return Tool::as('search_characters')
            ->for('게임의 캐릭터를 이름 키워드로 검색한다(속성/이미지 포함).')
            ->withStringParameter('game', '게임 이름(블루아카이브/니케/트릭컬/브라운더스트2)')
            ->withStringParameter('keyword', '캐릭터 이름 일부(예: 키사키, 우로스)')
            ->using(function (string $game, string $keyword) {
                $slug = $this->resolveGame($game);
                $this->track('search_characters', "캐릭터 검색 · {$keyword}");
                $gameModel = $slug ? Game::where('slug', $slug)->first() : null;
                if ($gameModel === null) {
                    return '게임을 특정할 수 없습니다. 게임 이름을 알려주세요.';
                }
                $chars = Character::where('subculture_game_id', $gameModel->id)
                    ->where('active_flg', true)
                    ->when($keyword !== '', fn ($q) => $q->where('name', 'like', "%{$keyword}%"))
                    ->limit(12)->get();
                if ($chars->isEmpty()) {
                    return "'{$keyword}' 에 맞는 캐릭터를 찾지 못했습니다.";
                }
                $items = $chars->map(fn (Character $c) => [
                    'name' => $c->name, 'image_url' => $c->display_image_url, 'traits' => $c->traits,
                ])->all();
                $this->card('characters', ['game' => $gameModel->name, 'items' => $items]);

                return '캐릭터 '.count($items).'명: '.$chars->pluck('name')->implode(', ');
            });
    }

    private function eventChallengesTool(): \Prism\Prism\Tool
    {
        return Tool::as('get_event_challenges')
            ->for('블루 아카이브 진행 중 이벤트 챌린지의 스테이지별 추천 조합·공략 영상을 조회한다.')
            ->withStringParameter('game', '게임 이름(현재 블루아카이브만 지원)', false)
            ->using(function (string $game = 'bluearchive') {
                $slug = $this->resolveGame($game) ?: 'bluearchive';
                $this->track('get_event_challenges', '이벤트 챌린지 조회');
                $gameModel = Game::where('slug', $slug)->first();
                if ($gameModel === null) {
                    return '지원하지 않는 게임입니다.';
                }
                $stages = EventChallenge::where('subculture_game_id', $gameModel->id)
                    ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString()))
                    ->orderBy('event_key')->orderBy('stage_label')->get();
                if ($stages->isEmpty()) {
                    return '진행 중인 이벤트 챌린지가 없습니다.';
                }
                $items = $stages->map(fn (EventChallenge $c) => [
                    'event' => $c->event_name, 'label' => $c->stage_label, 'condition' => $c->clear_condition,
                    'best_party' => $c->best_party ?? [], 'video_url' => $c->video_url,
                ])->all();
                $this->card('event_challenges', ['items' => $items]);

                $lines = $stages->map(fn ($c) => "{$c->stage_label}: ".collect($c->best_party ?? [])->pluck('name')->implode(', '))->implode("\n");

                return '이벤트 챌린지 '.count($items)."스테이지:\n".$lines;
            });
    }

    private function guidePostsTool(): \Prism\Prism\Tool
    {
        return Tool::as('get_guide_posts')
            ->for('게임의 최근 커뮤니티(디시·아카) 공략글 목록을 조회한다.')
            ->withStringParameter('game', '게임 이름')
            ->withStringParameter('keyword', '보스명·주제 키워드(선택)', false)
            ->using(function (string $game, string $keyword = '') {
                $slug = $this->resolveGame($game);
                $this->track('get_guide_posts', '공략글 조회');
                $gameModel = $slug ? Game::where('slug', $slug)->first() : null;
                if ($gameModel === null) {
                    return '게임을 특정할 수 없습니다.';
                }
                $posts = GuidePost::where('subculture_game_id', $gameModel->id)
                    ->when($keyword !== '', fn ($q) => $q->where('title', 'like', "%{$keyword}%"))
                    ->orderByRaw('CASE WHEN subculture_raid_id IS NOT NULL THEN 0 ELSE 1 END')
                    ->orderByDesc('rate')->orderByDesc('posted_at')->limit(10)->get();
                if ($posts->isEmpty()) {
                    return '최근 공략글이 없습니다.';
                }
                $items = $posts->map(fn (GuidePost $p) => [
                    'title' => $p->title, 'url' => $p->url, 'source' => $p->source,
                    'rate' => $p->rate, 'posted_at' => $p->posted_at?->toDateString(),
                ])->all();
                $this->card('guide_posts', ['game' => $gameModel->name, 'items' => $items]);

                return '공략글 '.count($items).'건: '.$posts->pluck('title')->take(5)->implode(' / ');
            });
    }

    private function attributePartiesTool(): \Prism\Prism\Tool
    {
        return Tool::as('get_attribute_parties')
            ->for('트릭컬 리바이브의 성격(속성)별 추천 조합을 조회한다.')
            ->withStringParameter('game', '게임 이름(트릭컬)', false)
            ->using(function (string $game = 'trickcal') {
                $slug = $this->resolveGame($game) ?: 'trickcal';
                $this->track('get_attribute_parties', '속성별 조합 조회');
                $gameModel = Game::where('slug', $slug)->first();
                if ($gameModel === null) {
                    return '지원하지 않는 게임입니다.';
                }
                $groups = $this->attributeParties->list($gameModel)->filter(fn ($g) => count($g['parties']) > 0)->values();
                if ($groups->isEmpty()) {
                    return '속성별 추천 조합 데이터가 없습니다.';
                }
                $this->card('attribute_parties', ['items' => $groups->all()]);
                $lines = $groups->map(fn ($g) => $g['label'].': '
                    .collect($g['parties'][0]['members'] ?? [])->pluck('name')->implode(', '))->implode("\n");

                return "속성별 추천 조합:\n".$lines;
            });
    }

    // ── 실시간 도구(하이브리드의 '최신' 축) ─────────────────────

    private function communitySearchTool(): \Prism\Prism\Tool
    {
        return Tool::as('search_community')
            ->for('디시·아카라이브 커뮤니티에서 실시간으로 글을 제목 검색한다. DB에 없는 최신 소식·평가가 필요할 때만 사용.')
            ->withStringParameter('game', '게임 이름')
            ->withStringParameter('keyword', '검색 키워드(보스명·캐릭터명·이벤트명 등)')
            ->using(function (string $game, string $keyword) {
                $slug = $this->resolveGame($game);
                $this->track('search_community', "커뮤니티 검색 · {$keyword}");
                if ($slug === '') {
                    return '게임을 특정할 수 없습니다.';
                }
                $posts = collect($this->dc->searchPosts($slug, $keyword))
                    ->concat($this->arca->searchPosts($slug, $keyword))
                    ->sortByDesc(fn ($p) => $p->postedAt?->getTimestamp() ?? 0)
                    ->take(8)
                    ->values();
                if ($posts->isEmpty()) {
                    return "'{$keyword}' 커뮤니티 검색 결과가 없습니다.";
                }
                $items = $posts->map(fn ($p) => [
                    'title' => $p->title, 'url' => $p->url,
                    'source' => str_contains($p->url, 'arca.live') ? 'arca' : 'dc',
                    'posted_at' => $p->postedAt?->toDateString(),
                ])->all();
                $this->card('guide_posts', ['game' => $this->gameName($slug), 'items' => $items]);

                return '커뮤니티 글 '.count($items)."건:\n"
                    .$posts->map(fn ($p) => "- {$p->title} ({$p->postedAt?->toDateString()})")->implode("\n");
            });
    }

    private function livePageTool(): \Prism\Prism\Tool
    {
        return Tool::as('fetch_live_page')
            ->for('서브컬쳐 정보 사이트의 페이지 본문을 실시간으로 읽는다. search_community 등 다른 도구가 알려준 URL 의 내용을 확인할 때 사용.')
            ->withStringParameter('url', '읽을 페이지 URL(허용된 서브컬쳐 정보 사이트만 가능)')
            ->using(function (string $url) {
                $this->track('fetch_live_page', '페이지 확인 중');

                // 화이트리스트 가드 — 서브컬쳐 소스 도메인만 허용(SSRF·주제 이탈 방지)
                $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
                $allowed = collect(config('subculture-agent.allowed_fetch_hosts', []))
                    ->contains(fn (string $h) => $host === $h || str_ends_with($host, '.'.$h));
                if (! $allowed || ! str_starts_with($url, 'http')) {
                    return '허용되지 않은 사이트입니다. 서브컬쳐 정보 사이트만 읽을 수 있어요.';
                }

                // 일반 HTTP 시도 후 차단(Cloudflare 등)이면 실브라우저 폴백
                $html = $this->getHtml($url) ?? $this->browser->fetchHtml($url);
                if ($html === null) {
                    return '페이지를 불러오지 못했습니다.';
                }

                $text = mb_substr($this->stripToText($html), 0, 2500);
                $this->card('live_page', ['url' => $url, 'excerpt' => mb_substr($text, 0, 300)]);

                return "페이지 내용(발췌):\n{$text}";
            });
    }

    // ── 공용 헬퍼 ──────────────────────────────────────────────

    private function track(string $name, string $label): void
    {
        $this->toolCalls[] = ['name' => $name, 'label' => $label];
    }

    private function card(string $type, array $data): void
    {
        $this->cards[] = ['type' => $type, 'data' => $data];
    }

    /** 게임 별칭(한국어 통칭) → slug. 공백을 제거해 "블루 아카이브" 같은 띄어쓰기 표기도 흡수. 못 찾으면 빈 문자열. */
    private function resolveGame(string $input): string
    {
        $input = preg_replace('/\s+/u', '', mb_strtolower(trim($input))) ?? '';
        if ($input === '') {
            return '';
        }
        static $aliases = [
            'genshin' => ['원신', 'genshin'],
            'starrail' => ['스타레일', '스레', '붕스', 'starrail', 'hsr'],
            'zenless' => ['젠레스', '젠존제', 'zzz'],
            'bluearchive' => ['블루아카이브', '블아', 'bluearchive'],
            'wuthering' => ['명조', 'wuwa', 'wuthering'],
            'trickcal' => ['트릭컬', 'trickcal'],
            'nikke' => ['니케', 'nikke', '승리의여신'],
            'browndust2' => ['브라운더스트', '브더2', 'bd2', 'browndust'],
        ];
        foreach ($aliases as $slug => $names) {
            if ($input === $slug) {
                return $slug;
            }
            foreach ($names as $n) {
                if (mb_strpos($input, $n) !== false) {
                    return $slug;
                }
            }
        }

        return '';
    }

    private function gameName(string $slug): string
    {
        return Game::where('slug', $slug)->value('name') ?? $slug;
    }
}
