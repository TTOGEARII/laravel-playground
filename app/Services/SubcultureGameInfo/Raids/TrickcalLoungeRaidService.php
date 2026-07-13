<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\Gemini\GeminiResponseParser;
use App\Services\Gemini\GeminiService;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\DcGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\NaverGameLoungeDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * 트릭컬 레이드 일정 보완 — 네이버 라운지 업데이트 공지에서 진행 중 시즌 일정을 파싱한다.
 *
 * 트릭컬 레코드(실측 통계 사이트)는 시즌이 끝난 뒤에야 게시되는 특성이 있어,
 * 진행 중 회차가 대시보드에 비는 공백을 공지 일정("엘리아스 프론티어 7월 정규 시즌…
 * 07/09 ~ 07/16")으로 메운다. 편성·통계는 없고 일정 카드만 만들며,
 * 이후 트릭컬 레코드에 정식 회차가 올라오면 기간이 겹치는 라운지 레이드를 정리한다.
 */
class TrickcalLoungeRaidService
{
    private const LOUNGE_ID = 'Trickcal';

    private const UPDATE_BOARD_ID = 11; // ⭐️업데이트 게시판

    /** 공지에서 찾을 콘텐츠: 키워드 → [raid_type, external_key 접두] */
    private const CONTENTS = [
        '엘리아스 프론티어' => ['프론티어', 'frontier-lounge'],
        '차원 대충돌' => ['차원 대충돌', 'clash-lounge'],
    ];

    use FetchesWebContent;

    public function __construct(
        private NaverGameLoungeDriver $lounge,
        private DcGuidePostDriver $dc,
        private ArcaGuidePostDriver $arca,
        private CrawlerScriptRunner $browser,
        private RaidSyncService $raidSync,
        private GeminiService $gemini,
    ) {}

    /** @return int 생성/갱신한 레이드 수 */
    public function sync(Game $game): int
    {
        $posts = $this->lounge->boardPosts(self::LOUNGE_ID, self::UPDATE_BOARD_ID, 15);
        if ($posts === []) {
            Log::info('[SGI-RAID] 트릭컬 라운지 공지 조회 결과 없음');

            return 0;
        }

        $synced = 0;
        foreach ($posts as $post) {
            foreach (self::CONTENTS as $keyword => [$raidType, $keyPrefix]) {
                $schedule = $this->parseSchedule($post['body'], $keyword, $post['date']);
                if ($schedule === null) {
                    continue;
                }
                [$startsAt, $endsAt] = $schedule;

                // 이미 종료된 시즌 공지(과거 글)는 스킵
                if ($endsAt->isPast()) {
                    continue;
                }

                // 같은 기간을 덮는 정식(트릭컬 레코드) 레이드가 이미 있으면 만들지 않는다
                $covered = Raid::where('subculture_game_id', $game->id)
                    ->where('source', '!=', 'naver-lounge')
                    ->where('raid_type', $raidType)
                    ->whereDate('starts_at', '<=', $endsAt)
                    ->whereDate('ends_at', '>=', $startsAt)
                    ->exists();
                if ($covered) {
                    continue;
                }

                Raid::updateOrCreate(
                    [
                        'subculture_game_id' => $game->id,
                        'external_key' => $keyPrefix.'-'.$startsAt->format('Y-m'),
                    ],
                    [
                        'name' => $keyword.' ('.$startsAt->format('n').'월 시즌)',
                        'boss_name' => null,
                        'raid_type' => $raidType,
                        'tags' => ['source_note' => '라운지 공지 일정 — 편성·통계는 시즌 집계 후 반영'],
                        'starts_at' => $startsAt->toDateString(),
                        'ends_at' => $endsAt->toDateString(),
                        'source' => 'naver-lounge',
                        'source_url' => 'https://game.naver.com/lounge/'.self::LOUNGE_ID.'/board/'.self::UPDATE_BOARD_ID,
                    ],
                );
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * 트릭컬 레코드 정식 회차가 올라온 뒤, 기간이 겹치는 라운지 일정 레이드를 정리한다.
     *
     * @return int 정리한 수
     */
    public function pruneCovered(Game $game): int
    {
        $pruned = 0;
        $loungeRaids = Raid::where('subculture_game_id', $game->id)->where('source', 'naver-lounge')->get();
        foreach ($loungeRaids as $raid) {
            $covered = Raid::where('subculture_game_id', $game->id)
                ->where('source', '!=', 'naver-lounge')
                ->where('raid_type', $raid->raid_type)
                ->whereDate('starts_at', '<=', $raid->ends_at)
                ->whereDate('ends_at', '>=', $raid->starts_at)
                ->exists();
            if ($covered) {
                $raid->delete();
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * 진행 중인 라운지 일정 레이드에 커뮤니티 공략(디시·아카) 기반 추천 편성을 붙인다.
     * ① 커뮤니티 글 제목 빈도로 보스명("우로스 프론티어") 추정 → boss_name 보강(공략글 매칭에도 사용)
     * ② 공략성 글 본문을 모아 Gemini 로 편성 추출 — 캐릭터 마스터 닫힌 어휘 강제, 본문에
     *    실제 언급된 조합만(추정 금지). 실패/부재 시 편성 없음 유지(정직한 폴백).
     *
     * @return int 편성을 붙인 레이드 수
     */
    public function enrichParties(Game $game): int
    {
        $raids = Raid::where('subculture_game_id', $game->id)
            ->where('source', 'naver-lounge')
            ->whereDate('starts_at', '<=', now())
            ->whereDate('ends_at', '>=', now())
            ->get();
        if ($raids->isEmpty()) {
            return 0;
        }

        $enriched = 0;
        foreach ($raids as $raid) {
            $keyword = str_contains((string) $raid->name, '프론티어') ? '프론티어' : '대충돌';

            // 최근 커뮤니티 글(양쪽 소스, 시즌 시작 이후)
            $posts = collect($this->dc->searchPosts($game->slug, $keyword))
                ->concat($this->arca->searchPosts($game->slug, $keyword))
                ->filter(fn (GuidePostData $p) => $p->postedAt !== null
                    && $p->postedAt->toDateString() >= $raid->starts_at->toDateString());

            // ① 보스명 추정: "{이름} 프론티어" 제목 빈도 상위 + 캐릭터 마스터 존재 확인
            $names = Character::where('subculture_game_id', $game->id)->pluck('name');
            $bossVotes = [];
            foreach ($posts as $post) {
                if (preg_match('/([가-힣a-zA-Z]{2,12})\s*'.$keyword.'/u', $post->title, $m)
                    && $names->contains($m[1])) {
                    $bossVotes[$m[1]] = ($bossVotes[$m[1]] ?? 0) + 1;
                }
            }
            arsort($bossVotes);
            $boss = array_key_first($bossVotes);
            if ($boss !== null && $raid->boss_name === null) {
                $raid->update(['boss_name' => $boss, 'name' => $raid->name.' — '.$boss]);
            }

            // ② 공략성 글 본문 수집(추천수 상위, 최대 4편) → Gemini 편성 추출
            $guideLike = $posts
                ->filter(fn (GuidePostData $p) => preg_match('/(공략|편성|덱|조합|추천|클리어)/u', $p->title) === 1)
                ->sortByDesc(fn (GuidePostData $p) => $p->rate)
                ->take(4);

            $bodies = [];
            foreach ($guideLike as $post) {
                usleep(700_000);
                $body = $this->fetchGuideBody($post->url);
                if ($body !== null && mb_strlen($body) > 80) {
                    $bodies[] = ['title' => $post->title, 'url' => $post->url, 'text' => mb_substr($body, 0, 1200)];
                }
            }

            $parties = $bodies === [] ? [] : $this->extractParties($game, $raid, $bodies);
            if ($parties === []) {
                Log::info('[SGI-RAID] 프론티어 커뮤니티 편성 추출 결과 없음', ['raid_id' => $raid->id, '공략글' => count($bodies)]);

                continue;
            }

            // 자기 소스(naver-lounge) 편성만 갈아끼운다(manual 보존) — sync 계약 재사용
            $this->raidSync->sync($game, 'naver-lounge', [[
                'external_key' => $raid->external_key,
                'name' => $raid->name,
                'boss_name' => $raid->boss_name,
                'raid_type' => $raid->raid_type,
                'tags' => $raid->tags,
                'starts_at' => $raid->starts_at?->toDateString(),
                'ends_at' => $raid->ends_at?->toDateString(),
                'source_url' => $raid->source_url,
                'parties' => $parties,
            ]]);
            $enriched++;
        }

        return $enriched;
    }

    /** 공략글 본문 텍스트 — 일반 HTTP 시도 후 차단(아카 CF)이면 실브라우저 폴백. */
    private function fetchGuideBody(string $url): ?string
    {
        $selector = str_contains($url, 'arca.live') ? '.article-content' : '.write_div';
        $html = $this->getHtml($url) ?? $this->browser->fetchHtml($url, $selector);
        if ($html === null) {
            return null;
        }

        try {
            $node = $this->xpath($html)
                ->query((new CssSelectorConverter)->toXPath($selector))
                ?->item(0);

            return $node instanceof \DOMElement
                ? trim($this->stripToText($node->ownerDocument?->saveHTML($node) ?: ''))
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 공략글 본문들 → Gemini 편성 추출(닫힌 어휘, 최대 3편성 × 9인).
     *
     * @param  array<int, array{title: string, url: string, text: string}>  $bodies
     * @return array<int, array> RaidSyncService 파티 계약
     */
    private function extractParties(Game $game, Raid $raid, array $bodies): array
    {
        if (! $this->gemini->hasApiKey()) {
            return [];
        }

        $nameToKey = Character::where('subculture_game_id', $game->id)
            ->where('active_flg', true)
            ->pluck('external_key', 'name')
            ->all();

        $prompt = "트릭컬 리바이브 '{$raid->name}' 공략글들에서 실제로 추천/사용된 사도 편성을 추출하라.\n"
            ."규칙:\n"
            ."- 사도 이름은 반드시 [사도 목록]의 표기 그대로만(목록에 없는 이름 금지).\n"
            ."- 본문에 실제 언급된 조합만. 추정으로 채우지 마라. 없으면 빈 배열.\n"
            ."- 편성당 최대 9명, 최대 3개. title 은 출처를 알 수 있게 짧게.\n"
            ."- 응답은 JSON 배열만: [{\"title\": \"…\", \"members\": [\"이름\", …]}]\n\n"
            .'[사도 목록] '.implode(', ', array_keys($nameToKey))."\n\n"
            .'[공략글] '.json_encode($bodies, JSON_UNESCAPED_UNICODE);

        $raw = $this->gemini->generate($prompt, temperature: 0.3, json: true);
        $parsed = $raw !== null ? GeminiResponseParser::parseJson($raw) : null;
        if (! is_array($parsed)) {
            return [];
        }

        $parties = [];
        $sort = 0;
        foreach (array_slice($parsed, 0, 3) as $row) {
            $members = collect((array) ($row['members'] ?? []))
                ->filter(fn ($n) => is_string($n) && isset($nameToKey[$n]))
                ->unique()
                ->take(9)
                ->values();
            if ($members->count() < 3) {
                continue; // 3명 미만은 편성으로 보지 않는다
            }
            $parties[] = [
                'title' => mb_substr((string) ($row['title'] ?? '커뮤니티 편성'), 0, 60),
                'difficulty' => null,
                'sort' => $sort++,
                'source_url' => $bodies[0]['url'],
                'note' => '커뮤니티 공략 발췌',
                'members' => $members->map(fn (string $n, int $i) => [
                    'external_key' => $nameToKey[$n],
                    'name' => $n,
                    'slot_type' => null,
                    'sort' => $i,
                    'note' => null,
                ])->all(),
            ];
        }

        return $parties;
    }

    /**
     * 공지 본문에서 "{키워드} … 진행 기간 … MM/DD(…) ~ MM/DD(…)"를 파싱한다.
     * 연도는 공지 작성일 기준(월 역전 시 이듬해 보정).
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function parseSchedule(string $body, string $keyword, string $postedAt): ?array
    {
        $idx = mb_stripos($body, $keyword);
        if ($idx === false) {
            return null;
        }

        // 키워드 뒤 일정 구간(진행 기간 표)만 본다 — 다른 콘텐츠 일정과 섞이지 않게 700자 한도
        $section = mb_substr($body, $idx, 700);
        if (! preg_match_all('~(\d{1,2})\s*/\s*(\d{1,2})\s*\([월화수목금토일]\)~u', $section, $m, PREG_SET_ORDER) || count($m) < 2) {
            return null;
        }

        $baseYear = (int) (Carbon::parse($postedAt ?: 'now')->format('Y'));
        $toDate = function (array $match) use ($baseYear): Carbon {
            return Carbon::createFromDate($baseYear, (int) $match[1], (int) $match[2]);
        };

        $start = $toDate($m[0]);
        // 종료일은 구간 내 가장 늦은 날짜(성격 구역/보스 구역 등 여러 기간 중 최종 종료)
        $end = collect($m)->map($toDate)->max();
        if ($end->lt($start)) {
            $end = $end->addYear(); // 연말 걸침 보정
        }

        return [$start, $end];
    }
}
