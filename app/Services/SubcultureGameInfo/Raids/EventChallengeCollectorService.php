<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\EventChallenge;
use App\Models\SubcultureGameInfo\Game;
use App\Services\Gemini\GeminiResponseParser;
use App\Services\Gemini\GeminiService;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\DcGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * 이벤트 챌린지 공략 수집(블아) — 아카 채널의 이벤트 '올인원' 글을 찾아
 * 챌린지 섹션(>> Challenge 01 …)을 스테이지 단위로 파싱한다(Gemini 불필요, 결정적 파싱).
 *
 * 글 구조 규칙(2026-07 실측):
 *  - ">> Challenge {NN|EX} {맵이름} ({클리어 조건})" 헤더 뒤로 공략 텍스트가 이어지고
 *  - 스테이지마다 유튜브 임베드(iframe)가 붙는다.
 *  - 조합은 표가 아닌 본문 언급이라, 캐릭터 마스터와 이름 매칭해 '언급 캐릭터'로 저장한다.
 */
class EventChallengeCollectorService
{
    use FetchesWebContent;

    public function __construct(
        private ArcaGuidePostDriver $arca,
        private DcGuidePostDriver $dc,
        private CrawlerScriptRunner $browser,
        private GeminiService $gemini,
    ) {}

    /**
     * @return array{event: ?string, stages: int, pruned: int}
     */
    public function collect(Game $game): array
    {
        $cfg = config('subculture-game-info.raids.event_challenges');
        $stats = ['event' => null, 'stages' => 0, 'pruned' => 0];

        // 후보: 제목이 시리즈 형식("저장용 … 올인원")인 최신 글부터 시도
        $requiredWords = (array) ($cfg['require_title_words'] ?? [$cfg['search_keyword']]);
        $candidates = collect($this->arca->searchPosts($game->slug, (string) $cfg['search_keyword']))
            ->filter(fn (GuidePostData $p) => collect($requiredWords)
                ->every(fn (string $word) => mb_stripos($p->title, $word) !== false))
            ->sortByDesc(fn (GuidePostData $p) => $p->postedAt?->getTimestamp() ?? 0)
            ->take(3);

        foreach ($candidates as $post) {
            usleep((int) ((float) $cfg['fetch_delay_seconds'] * 1_000_000));
            // 아카 글 페이지는 Cloudflare 가 일반 HTTP 를 차단하는 경우가 있어
            // 먼저 가볍게 시도하고, 막히면 실브라우저(사이드카)로 폴백한다.
            $html = $this->getHtml($post->url)
                ?? $this->browser->fetchHtml($post->url, '.article-content');
            if ($html === null) {
                continue;
            }

            $stages = $this->parseChallenges($html, $game);
            if ($stages === []) {
                continue; // 챌린지 섹션이 없는 올인원(종전시 등) — 다음 후보
            }

            $eventName = $this->eventNameFromTitle($post->title, (string) $cfg['search_keyword']);
            [$startsAt, $endsAt] = $this->parsePeriod($html);

            // 보조 영상(유튜브 검색·디시 챌린지 글)을 스테이지에 매핑해 붙인다 — 실패해도 본 수집은 진행
            try {
                $stages = $this->attachExtraVideos($stages, $eventName, $game, $cfg, $startsAt);
            } catch (\Throwable $e) {
                Log::warning('[SGI-EVENT] 보조 영상 수집 실패(본 수집은 진행)', ['error' => $e->getMessage()]);
            }

            // 공략 재료(요약·영상 제목·언급)를 Gemini 로 정리해 스테이지별 추천 조합 추출
            try {
                $stages = $this->extractBestParties($stages, $game);
            } catch (\Throwable $e) {
                Log::warning('[SGI-EVENT] 추천 조합 추출 실패(본 수집은 진행)', ['error' => $e->getMessage()]);
            }

            foreach ($stages as $stage) {
                EventChallenge::updateOrCreate(
                    [
                        'subculture_game_id' => $game->id,
                        'event_key' => $post->externalId,
                        'stage_label' => $stage['label'],
                    ],
                    [
                        'event_name' => $eventName,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'stage_name' => $stage['name'],
                        'clear_condition' => $stage['condition'],
                        'summary' => $stage['summary'],
                        'video_url' => $stage['video'],
                        'extra_videos' => $stage['extra_videos'] ?? [],
                        'best_party' => $stage['best_party'] ?? [],
                        'mentioned' => $stage['mentioned'],
                        'source_url' => $post->url,
                    ],
                );
            }

            // 이전 이벤트 스테이지 정리 — 대시보드는 항상 최신 이벤트 하나만 노출
            $stats['pruned'] = EventChallenge::where('subculture_game_id', $game->id)
                ->where('event_key', '!=', $post->externalId)
                ->delete();

            $stats['event'] = $eventName;
            $stats['stages'] = count($stages);

            return $stats;
        }

        Log::info('[SGI-EVENT] 챌린지 공략 올인원 글을 찾지 못함', ['game' => $game->slug]);

        return $stats;
    }

    /** 제목 → 이벤트명: "저장용 게임개발부 청소 대작전! 올인원" → "게임개발부 청소 대작전!" */
    private function eventNameFromTitle(string $title, string $keyword): string
    {
        $name = trim(str_ireplace(['저장용', $keyword], '', $title));

        return $name !== '' ? $name : $title;
    }

    /**
     * 본문에서 한섭 이벤트 기간을 찾는다. ">> 한섭 : 2026-06-30 ~ 2026-07-14"
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parsePeriod(string $html): array
    {
        $text = $this->stripToText($html);
        if (preg_match('/한섭\s*:?\s*(\d{4}-\d{2}-\d{2})\s*~\s*(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }

    /**
     * 본문 DOM 을 문서 순서(텍스트/iframe 토큰)로 훑어 챌린지 스테이지들을 뽑는다.
     *
     * @return array<int, array{label: string, name: ?string, condition: ?string, summary: ?string, video: ?string, mentioned: array}>
     */
    private function parseChallenges(string $html, Game $game): array
    {
        $xp = $this->xpath($html);
        $content = $xp->query((new CssSelectorConverter)->toXPath('.article-content'))?->item(0);
        if (! $content instanceof \DOMElement) {
            return [];
        }

        // 문서 순서 토큰화: 텍스트 조각 + iframe(영상)
        $tokens = [];
        foreach ($xp->query('.//text() | .//iframe', $content) as $node) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'iframe') {
                $tokens[] = ['iframe', (string) $node->getAttribute('src')];
            } else {
                $text = trim((string) $node->textContent);
                if ($text !== '') {
                    $tokens[] = ['text', $text];
                }
            }
        }

        $stages = [];
        $current = null;
        foreach ($tokens as [$type, $value]) {
            if ($type === 'text' && preg_match('/Challenge\s*(EX|\d+)/iu', $value, $m)) {
                if ($current !== null) {
                    $stages[] = $current;
                }
                $current = $this->newStage($value, strtoupper($m[1]));

                continue;
            }
            if ($current === null) {
                continue;
            }
            if ($type === 'iframe') {
                $current['video'] ??= $this->toWatchUrl($value);
            } else {
                $current['lines'][] = $value;
            }
        }
        if ($current !== null) {
            $stages[] = $current;
        }

        if ($stages === []) {
            return [];
        }

        $roster = $this->characterRoster($game);

        return array_map(function (array $stage) use ($roster) {
            // nbsp·빈 줄 정리 후 요약 생성
            $lines = array_values(array_filter(array_map(
                fn (string $line) => trim(str_replace("\u{00A0}", ' ', $line)),
                $stage['lines'],
            ), fn (string $line) => $line !== ''));
            $summary = mb_substr(implode("\n", $lines), 0, 600);

            return [
                'label' => $stage['label'],
                'name' => $stage['name'],
                'condition' => $stage['condition'],
                'summary' => $summary !== '' ? $summary : null,
                'video' => $stage['video'],
                'mentioned' => $this->mentionedCharacters($summary, $roster),
            ];
        }, $stages);
    }

    /** 헤더 한 줄 → 스테이지 뼈대. ">> Challenge 01 工業実習室・別館 (90초 이내 클리어)" */
    private function newStage(string $header, string $no): array
    {
        $name = null;
        $condition = null;
        if (preg_match('/Challenge\s*(?:EX|\d+)\s*(.*)$/iu', $header, $m)) {
            $rest = trim($m[1]);
            if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $rest, $mm)) {
                $name = trim($mm[1]) !== '' ? trim($mm[1]) : null;
                $condition = trim($mm[2]);
            } elseif ($rest !== '') {
                $name = $rest;
            }
        }

        return [
            'label' => 'Challenge '.$no,
            'name' => $name,
            'condition' => $condition,
            'video' => null,
            'lines' => [],
        ];
    }

    /** 유튜브 임베드 → 시청 URL (start 파라미터 유지). 유튜브가 아니면 원본 유지. */
    private function toWatchUrl(string $embedUrl): ?string
    {
        if ($embedUrl === '') {
            return null;
        }
        if (preg_match('~youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,})(?:\?[^#]*start=(\d+))?~', $embedUrl, $m)) {
            return 'https://www.youtube.com/watch?v='.$m[1].(isset($m[2]) && $m[2] !== '' ? '&t='.$m[2].'s' : '');
        }

        return $embedUrl;
    }

    /**
     * 캐릭터 사전: 마스터 이름 + 커뮤니티 애칭(종전시 aliases 재사용) → 마스터 이름.
     *
     * @return array<string, string> 표기 → 마스터 이름
     */
    private function characterRoster(Game $game): array
    {
        $roster = [];
        foreach (Character::where('subculture_game_id', $game->id)->where('active_flg', true)->pluck('name') as $name) {
            if (mb_strlen($name) >= 2) {
                $roster[$name] = $name;
            }
        }
        foreach ((array) config('subculture-game-info.raids.jfd.aliases', []) as $alias => $master) {
            $roster[$alias] = $master;
        }

        return $roster;
    }

    // ─── 추천 조합 추출(Gemini) ──────────────────────────────────────────

    /**
     * 공략 재료(스테이지 요약·영상 제목·언급 캐릭터)를 Gemini 로 정리해 스테이지별
     * 추천 조합을 뽑는다. 캐릭터는 마스터 이름 닫힌 어휘로 강제하고, 키 없음/실패 시
     * 언급 캐릭터를 조합으로 폴백한다(기능 자체는 항상 동작).
     */
    private function extractBestParties(array $stages, Game $game): array
    {
        $nameToKey = Character::where('subculture_game_id', $game->id)
            ->where('active_flg', true)
            ->pluck('external_key', 'name')
            ->all();

        $toParty = fn (array $names) => collect($names)
            ->filter(fn ($n) => is_string($n) && isset($nameToKey[$n]))
            ->unique()
            ->take(6)
            ->map(fn (string $n) => ['name' => $n, 'key' => $nameToKey[$n]])
            ->values()
            ->all();

        // 폴백(키 없음/실패 대비): 언급 캐릭터를 조합으로
        foreach ($stages as &$stage) {
            $stage['best_party'] = $toParty($stage['mentioned']);
        }
        unset($stage);

        if (! $this->gemini->hasApiKey()) {
            return $stages;
        }

        $material = collect($stages)->map(fn (array $s) => [
            'label' => $s['label'],
            'condition' => $s['condition'],
            'summary' => mb_substr((string) $s['summary'], 0, 400),
            'video_titles' => collect($s['extra_videos'] ?? [])->pluck('title')->filter()->values()->all(),
            'mentioned' => $s['mentioned'],
        ])->all();

        $prompt = "블루 아카이브 이벤트 챌린지 공략 자료를 정리해 스테이지별 추천 편성(최대 6명)을 뽑아라.\n"
            ."규칙:\n"
            ."- 캐릭터 이름은 반드시 아래 [캐릭터 목록]에 있는 표기 그대로만 사용한다(목록에 없는 이름 금지).\n"
            ."- 공략 요약·영상 제목에서 실제 사용/추천된 캐릭터를 우선하고, 부족한 자리는 클리어 조건과 기믹에 맞는 대중적인 픽으로 채운다.\n"
            ."- 응답은 JSON 배열만: [{\"label\": \"Challenge 01\", \"party\": [\"이름\", ...]}]\n\n"
            .'[캐릭터 목록] '.implode(', ', array_keys($nameToKey))."\n\n"
            .'[공략 자료] '.json_encode($material, JSON_UNESCAPED_UNICODE);

        // maxOutputTokens 를 걸면 thinking 토큰까지 포함돼 JSON 이 잘릴 수 있다 — 모델 기본값 사용
        $raw = $this->gemini->generate($prompt, temperature: 0.3, json: true);
        $parsed = $raw !== null ? GeminiResponseParser::parseJson($raw) : null;
        if (! is_array($parsed)) {
            Log::warning('[SGI-EVENT] 추천 조합 Gemini 응답 파싱 실패 — 언급 캐릭터로 폴백');

            return $stages;
        }

        $byLabel = collect($parsed)
            ->filter(fn ($row) => is_array($row) && isset($row['label']) && is_array($row['party'] ?? null))
            ->keyBy('label');

        foreach ($stages as &$stage) {
            $party = $toParty($byLabel[$stage['label']]['party'] ?? []);
            if ($party !== []) {
                $stage['best_party'] = $party;
            }
        }

        return $stages;
    }

    // ─── 보조 영상 소스: 유튜브 검색 · 디시 챌린지 글 ─────────────────────

    /**
     * 유튜브 검색·디시 글에서 모은 관련 영상을 제목의 스테이지 표기('챌린지 3'/'챌 EX')로
     * 각 스테이지에 매핑해 extra_videos 로 붙인다. 주 영상과 중복(같은 영상 ID)은 제외.
     */
    private function attachExtraVideos(array $stages, string $eventName, Game $game, array $cfg, ?string $startsAt): array
    {
        $labels = array_column($stages, 'label');
        $maxPerStage = (int) ($cfg['max_extra_videos_per_stage'] ?? 3);

        // 커뮤니티(디시) 글을 유튜브 검색보다 앞에 둔다 — 상한에 걸릴 때 큐레이션된 쪽 우선
        $candidates = [];
        if (($cfg['dc']['enabled'] ?? false) === true) {
            foreach ($this->dcChallengeVideos($game, $cfg, $startsAt) as $video) {
                $candidates[] = $video + ['source' => 'dc'];
            }
        }
        if (($cfg['youtube']['enabled'] ?? false) === true) {
            $query = str_replace('{event}', $eventName, (string) $cfg['youtube']['query_template']);
            foreach ($this->youtubeSearchVideos($query) as $video) {
                $candidates[] = $video + ['source' => 'youtube'];
            }
        }

        foreach ($stages as &$stage) {
            $primaryId = $this->youtubeId((string) ($stage['video'] ?? ''));
            $picked = [];
            $seen = $primaryId !== null ? [$primaryId => true] : [];

            foreach ($candidates as $candidate) {
                if (count($picked) >= $maxPerStage) {
                    break;
                }
                if (! in_array($stage['label'], $this->stageLabelsFromTitle($candidate['title'], $labels), true)) {
                    continue;
                }
                $id = $this->youtubeId($candidate['url']);
                if ($id === null || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $picked[] = [
                    'url' => $candidate['url'],
                    'title' => mb_substr($candidate['title'], 0, 120),
                    'source' => $candidate['source'],
                ];
            }
            $stage['extra_videos'] = $picked;
        }

        return $stages;
    }

    /**
     * 유튜브 검색 결과(ytInitialData JSON) 파싱 — API 키 없이 검색 페이지 HTML 에서 추출.
     *
     * @return array<int, array{url: string, title: string}>
     */
    private function youtubeSearchVideos(string $query): array
    {
        $html = $this->getHtml('https://www.youtube.com/results', ['search_query' => $query]);
        if ($html === null || ! preg_match('/var ytInitialData = (\{.+?\});<\/script>/s', $html, $m)) {
            Log::info('[SGI-EVENT] 유튜브 검색 결과 파싱 실패', ['query' => $query]);

            return [];
        }

        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            return [];
        }

        $videos = [];
        $this->collectVideoRenderers($data, $videos);

        return array_slice($videos, 0, 20);
    }

    /** ytInitialData 트리에서 videoRenderer(영상 ID·제목)를 재귀 수집한다. */
    private function collectVideoRenderers(array $node, array &$videos): void
    {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'videoRenderer' && isset($value['videoId'])) {
                $title = implode('', array_column($value['title']['runs'] ?? [], 'text'));
                if ($title !== '') {
                    $videos[] = [
                        'url' => 'https://www.youtube.com/watch?v='.$value['videoId'],
                        'title' => $title,
                    ];
                }

                continue;
            }
            $this->collectVideoRenderers($value, $videos);
        }
    }

    /**
     * 디시에서 이벤트 기간 내 챌린지 글을 찾아 본문의 유튜브 링크를 모은다.
     * 글 제목에 스테이지 표기가 있어야 매핑 가능하므로 그런 글만 사용한다.
     *
     * @return array<int, array{url: string, title: string}>
     */
    private function dcChallengeVideos(Game $game, array $cfg, ?string $startsAt): array
    {
        $posts = collect($this->dc->searchPosts($game->slug, (string) $cfg['dc']['search_keyword']))
            ->filter(fn (GuidePostData $p) => $startsAt === null
                || ($p->postedAt !== null && $p->postedAt->toDateString() >= $startsAt))
            ->filter(fn (GuidePostData $p) => preg_match('/(?:챌린지|챌|challenge)\s*[.\-]?\s*(?:EX|이엑스|\d+)/iu', $p->title) === 1)
            ->sortByDesc(fn (GuidePostData $p) => $p->rate)
            ->take((int) ($cfg['dc']['max_posts'] ?? 8));

        $videos = [];
        foreach ($posts as $post) {
            usleep(500_000); // 연속 요청 간격
            $body = $this->getHtml($post->url);
            if ($body === null) {
                continue;
            }
            if (preg_match_all('~(?:youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $body, $m)) {
                foreach (array_unique($m[1]) as $id) {
                    $videos[] = [
                        'url' => 'https://www.youtube.com/watch?v='.$id,
                        'title' => $post->title, // 영상 자체 제목은 알 수 없어 글 제목으로 표기
                    ];
                }
            }
        }

        return $videos;
    }

    /**
     * 제목에서 스테이지 표기를 찾아 라벨 목록으로 변환. "챌린지 1,2 & EX" 처럼 여러 개면 전부 반환.
     *
     * @param  string[]  $labels  존재하는 스테이지 라벨들
     * @return string[]
     */
    private function stageLabelsFromTitle(string $title, array $labels): array
    {
        if (! preg_match_all('/(?:챌린지|챌|challenge)\s*[.\-]?\s*(EX|이엑스|\d+)/iu', $title, $m)) {
            return [];
        }

        $found = [];
        foreach ($m[1] as $token) {
            $label = in_array(mb_strtoupper($token), ['EX', '이엑스'], true)
                ? 'Challenge EX'
                : 'Challenge '.str_pad((string) (int) $token, 2, '0', STR_PAD_LEFT);
            if (in_array($label, $labels, true)) {
                $found[$label] = true;
            }
        }

        return array_keys($found);
    }

    /** 유튜브 URL → 영상 ID (중복 제거용). */
    private function youtubeId(string $url): ?string
    {
        return preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)
            ? $m[1]
            : null;
    }

    /**
     * 요약 텍스트에 언급된 캐릭터를 마스터 이름으로 수집(등장 순서, 최대 10명).
     * 긴 표기(애칭 포함)부터 매칭하고 잡은 구간은 지워서, '드히나' 안의 '히나' 같은 중복 매칭을 막는다.
     * 토큰 앞이 한글이면 다른 단어의 꼬리로 보고 버린다('임토키' 안의 '토키' 등 적 유닛 오인 방지).
     *
     * @param  array<string, string>  $roster
     */
    private function mentionedCharacters(string $text, array $roster): array
    {
        $tokens = array_keys($roster);
        usort($tokens, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $found = [];
        foreach ($tokens as $token) {
            $offset = 0;
            while (($pos = mb_stripos($text, $token, $offset)) !== false) {
                $prev = $pos > 0 ? mb_substr($text, $pos - 1, 1) : '';
                if ($prev !== '' && preg_match('/[가-힣]/u', $prev)) {
                    $offset = $pos + 1; // 앞이 한글 = 다른 단어의 일부 — 다음 등장 위치 탐색

                    continue;
                }
                $master = $roster[$token];
                $found[$master] = min($found[$master] ?? PHP_INT_MAX, $pos);
                // 잡은 구간은 지워 하위(짧은) 토큰의 중복 매칭 방지
                $text = mb_substr($text, 0, $pos).str_repeat(' ', mb_strlen($token)).mb_substr($text, $pos + mb_strlen($token));
                break;
            }
        }
        asort($found);

        return array_slice(array_keys($found), 0, 10);
    }
}
