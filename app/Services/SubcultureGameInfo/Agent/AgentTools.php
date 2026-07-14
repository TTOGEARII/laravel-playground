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

    /** 현재 대화의 로그인 사용자 id(내 캐릭터풀 조회용) — 요청마다 서비스가 세션에서 주입, 비로그인은 null. */
    public ?int $userId = null;

    public function __construct(
        private RedeemCodeService $codes,
        private RaidQueryService $raids,
        private AttributePartyService $attributeParties,
        private DcGuidePostDriver $dc,
        private ArcaGuidePostDriver $arca,
        private CrawlerScriptRunner $browser,
        private \App\Services\SubcultureGameInfo\Sources\YoutubeSearchClient $youtube,
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
            $this->youtubeVideosTool(),
            $this->myCharactersTool(),
            $this->livePageTool(),
        ];
    }

    /**
     * 내 캐릭터 풀(로그인 사용자의 보유 캐릭터) 조회 — "내 캐릭터로 조합" 류 질문에 사용.
     * DB(subculture_user_characters)에서 owned_flg 인 캐릭터를 게임별로 돌려준다.
     */
    private function myCharactersTool(): \Prism\Prism\Tool
    {
        return Tool::as('get_my_characters')
            ->for('로그인한 사용자가 보유한 캐릭터(내 캐릭터 풀)를 게임별로 조회한다. '
                .'"내 캐릭터", "내가 가진", "내 보유로 조합" 같은 요청에 사용. game 을 비우면 전체 게임.')
            ->withStringParameter('game', '게임 이름(예: 블루아카이브, 명조). 전체면 빈 문자열', false)
            ->using(function (string $game = '') {
                if ($this->userId === null) {
                    return '로그인하지 않아 내 캐릭터 풀을 볼 수 없습니다. 로그인 후 캐릭터정보에서 보유를 표시해 주세요.';
                }
                $slug = $this->resolveGame($game);
                $this->track('get_my_characters', '내 캐릭터 풀 조회'.($slug ? " · {$this->gameName($slug)}" : ''));

                $rows = \App\Models\SubcultureGameInfo\UserCharacter::query()
                    ->where('user_id', $this->userId)
                    ->where('owned_flg', true)
                    ->whereHas('character', fn ($q) => $slug
                        ? $q->whereHas('game', fn ($g) => $g->where('slug', $slug))
                        : $q)
                    ->with(['character.game'])
                    ->get()
                    ->filter(fn ($uc) => $uc->character !== null);

                if ($rows->isEmpty()) {
                    return $slug
                        ? "{$this->gameName($slug)}에서 보유 표시한 캐릭터가 없습니다."
                        : '아직 보유 표시한 캐릭터가 없습니다. 캐릭터정보 탭에서 보유를 체크해 주세요.';
                }

                $byGame = $rows->groupBy(fn ($uc) => $uc->character->game?->name ?? '기타');
                $items = [];
                $lines = [];
                foreach ($byGame as $gameName => $group) {
                    $names = $group->map(fn ($uc) => $uc->character->name)->sort()->values();
                    $items[] = ['game' => $gameName, 'characters' => $names->all()];
                    $lines[] = "{$gameName}({$names->count()}): ".$names->implode(', ');
                }
                $this->card('my_characters', ['total' => $rows->count(), 'games' => $items]);

                return "내 보유 캐릭터 {$rows->count()}명:\n".implode("\n", $lines);
            });
    }

    /** 유튜브 영상 검색 — 공략/가이드 영상 요청에 사용(링크 카드 제공). */
    private function youtubeVideosTool(): \Prism\Prism\Tool
    {
        return Tool::as('search_youtube_videos')
            ->for('유튜브에서 공략·가이드 영상을 검색해 제목과 링크를 돌려준다. '
                .'query 에는 게임명과 보스/컨텐츠명을 함께 넣는다(예: "블루아카이브 예로니무스 대결전 공략").')
            ->withStringParameter('query', '유튜브 검색어(게임명 포함 권장)')
            ->using(function (string $query) {
                $query = mb_substr(trim($query), 0, 100);
                if ($query === '') {
                    return '검색어가 비어 있습니다.';
                }
                $this->track('search_youtube_videos', "유튜브 영상 검색 · {$query}");

                $videos = array_slice($this->youtube->search($query, 6), 0, 6);
                if ($videos === []) {
                    return "'{$query}' 유튜브 검색 결과가 없습니다.";
                }

                $this->card('videos', [
                    'query' => $query,
                    'items' => array_map(fn (array $v) => [
                        'title' => $v['title'],
                        'url' => $v['url'],
                        'thumbnail' => "https://i.ytimg.com/vi/{$v['video_id']}/mqdefault.jpg",
                    ], $videos),
                ]);

                $lines = array_map(fn (array $v) => "- {$v['title']} ({$v['url']})", $videos);

                return '유튜브 영상 '.count($videos)."개(카드로도 표시됨):\n".implode("\n", $lines);
            });
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
            ->for('게임의 캐릭터 정보를 조회한다 — 모든 게임 지원(블루아카이브·니케·트릭컬·브라운더스트2·명조·원신·스타레일·젠존제). '
                .'정보검색 DB 를 그대로 참조하며, 캐릭터의 티어·속성/무기·에코(유물)세트·최고 무기·추천 스탯·강화 재료·추천 조합 영상까지 반환한다. '
                .'"미야비 티어", "카를로타 에코세트", "OO 재료/무기/조합" 같은 캐릭터 빌드 질문에 사용.')
            ->withStringParameter('game', '게임 이름(블루아카이브/명조/원신/스타레일/젠존제/니케/트릭컬/브라운더스트2)')
            ->withStringParameter('keyword', '캐릭터 이름 일부(예: 미야비, 카를로타). 목록 전체면 빈 문자열', false)
            ->using(function (string $game, string $keyword = '') {
                $slug = $this->resolveGame($game);
                $this->track('search_characters', '캐릭터 검색'.($keyword !== '' ? " · {$keyword}" : ''));
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

                // 빌드 상세(티어·에코세트·무기·재료·조합)를 텍스트로 풀어 모델이 바로 답할 수 있게 한다.
                $lines = $chars->map(fn (Character $c) => '- '.$this->characterSummary($c))->implode("\n");

                return "{$gameModel->name} 캐릭터 ".count($items)."명:\n".$lines;
            });
    }

    /** 캐릭터 traits 를 사람이 읽는 요약 라인으로(존재하는 필드만). */
    private function characterSummary(Character $c): string
    {
        $t = (array) ($c->traits ?? []);
        $parts = [$c->name];

        if (! empty($t['tier'])) {
            $parts[] = "{$t['tier']}티어";
        }
        if (($c->rarity ?? null) !== null) {
            $parts[] = (string) $c->rarity;
        }
        // 속성/무기/역할류(게임별 배지 필드)
        $attrs = array_filter([
            $t['element'] ?? null, $t['weapon'] ?? null, $t['profession'] ?? null,
            $t['path'] ?? null, $t['bullet'] ?? null, $t['armor'] ?? null,
        ]);
        if ($attrs !== []) {
            $parts[] = implode('/', $attrs);
        }
        if (! empty($t['echo_sets']) && is_array($t['echo_sets'])) {
            $sets = collect($t['echo_sets'])
                ->map(fn ($e) => is_array($e) ? trim(($e['sonata'] ?? '').' '.($e['count'] ?? '')) : (string) $e)
                ->filter()->implode(', ');
            if ($sets !== '') {
                $parts[] = "에코세트: {$sets}";
            }
        }
        if (! empty($t['best_weapon']['name'])) {
            $parts[] = "무기: {$t['best_weapon']['name']}";
        }
        if (! empty($t['rec_weapons']) && is_array($t['rec_weapons'])) {
            $parts[] = '추천무기: '.implode(', ', array_slice($t['rec_weapons'], 0, 4));
        }
        if (! empty($t['rec_sets']) && is_array($t['rec_sets'])) {
            $parts[] = '추천세트: '.implode(', ', array_slice($t['rec_sets'], 0, 3));
        }
        if (! empty($t['best_stats']) && is_array($t['best_stats'])) {
            $parts[] = '추천스탯: '.implode(', ', array_slice($t['best_stats'], 0, 6));
        }
        if (! empty($t['materials']) && is_array($t['materials'])) {
            $mats = collect($t['materials'])->map(fn ($m) => is_array($m) ? ($m['name'] ?? '') : (string) $m)->filter()->implode(', ');
            if ($mats !== '') {
                $parts[] = "재료: {$mats}";
            }
        }
        if (! empty($t['comps']) && is_array($t['comps'])) {
            $parts[] = '추천 조합 영상 '.count($t['comps']).'개(카드 참고)';
        }

        return implode(' · ', $parts);
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
