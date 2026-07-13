<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Banner;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GameEvent;
use App\Models\SubcultureGameInfo\Raid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 몰루로그 미래시(/futures) 동기화 — 블아 KR 일정의 단일 출처.
 * SSR HTML 의 React Router 스트림(turbo-stream)에서 loaderData 를 디코드해:
 *   recruitments → Banner(모집중 학생·복각, 현재/미래시)
 *   event 류     → GameEvent(배너 이미지 포함, 현재/미래시)
 *   raidInfo     → Raid 일정 보강/선등록(보스 이미지·장갑별 난이도, crawl-raids 와 같은 키로 합류)
 * 종료된 컨텐츠는 저장하지 않는다(레이드는 회차 기록이라 예외).
 * 방어: 파싱 0건이면 sync 스킵(마크업 변경 사고 시 데이터 보존).
 */
class MollulogFuturesSyncService
{
    private const SOURCE = 'mollulog-futures';

    /** 몰루로그 raidType → crawl-raids(bluearchive.mjs)와 동일한 URL 슬러그(키 합류용). */
    private const RAID_SLUGS = ['total_assault' => 'total-assault', 'elimination' => 'grand-assault', 'unlimit' => 'unlimit'];

    private const RAID_TYPE_KR = ['total_assault' => '총력전', 'elimination' => '대결전', 'unlimit' => '제약해제결전'];

    private const TERRAIN_KR = ['outdoor' => '야외', 'street' => '시가지', 'indoor' => '실내'];

    private const DEFENSE_KR = ['light' => '경장갑', 'heavy' => '중장갑', 'special' => '특수장갑', 'elastic' => '탄력장갑'];

    private const DIFFICULTY_KR = ['torment' => '토먼트', 'insane' => '인세인', 'lunatic' => '루나틱', 'extreme' => '익스트림'];

    private const ATTACK_KR = ['explosive' => '폭발', 'piercing' => '관통', 'mystic' => '신비', 'sonic' => '진동'];

    /** 이벤트로 저장할 contentType → kind. (raid/pickup 은 별도 처리, joint_firing_drill 은 종전시 수집기가 담당) */
    private const EVENT_KINDS = [
        'event' => 'event', 'mini_event' => 'event', 'campaign' => 'event',
        'main_story' => 'story', 'mini_story' => 'story',
    ];

    /** @return array{banners:int,events:int,raids:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.mollulog_futures');
        $contents = $this->fetchContents((string) $cfg['url'], (int) ($cfg['timeout'] ?? 20));

        if ($contents === []) {
            Log::warning('[MollulogFutures] 컨텐츠 0건 — sync 스킵', ['game' => $game->slug]);

            return ['banners' => 0, 'events' => 0, 'raids' => 0];
        }

        $now = now();
        $bannerKeys = [];
        $eventKeys = [];
        $stats = ['banners' => 0, 'events' => 0, 'raids' => 0];

        foreach ($contents as $c) {
            if (! is_array($c) || empty($c['uid'])) {
                continue;
            }

            foreach ($this->upsertBanners($game, $c, $now) as $key) {
                $bannerKeys[] = $key;
                $stats['banners']++;
            }

            if (! empty($c['raidInfo'])) {
                if ($this->upsertRaid($game, $c, (string) $cfg['boss_image_base'])) {
                    $stats['raids']++;
                }
            } elseif (isset(self::EVENT_KINDS[$c['contentType'] ?? ''])) {
                $key = $this->upsertEvent($game, $c, $now);
                if ($key !== null) {
                    $eventKeys[] = $key;
                    $stats['events']++;
                }
            }
        }

        // 이번 sync 에 없는 배너/이벤트는 정리(멱등) — 옛 schaledb 소스 행도 이관 정리. manual 은 보존.
        Banner::forGame($game->id)->where('source', '!=', 'manual')->whereNotIn('external_key', $bannerKeys)->delete();
        GameEvent::forGame($game->id)->where('source', '!=', 'manual')->whereNotIn('external_key', $eventKeys)->delete();

        return $stats;
    }

    /* ---------------------------------- 수집/디코드 ---------------------------------- */

    /** @return array<int, array> futures loaderData contents */
    private function fetchContents(string $url, int $timeout): array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('subculture-game-info.http.user_agent')])
                ->timeout($timeout)->get($url);
            if (! $res->ok()) {
                Log::warning('[MollulogFutures] HTTP 실패', ['status' => $res->status()]);

                return [];
            }

            return $this->decodeContents($res->body());
        } catch (\Throwable $e) {
            Log::warning('[MollulogFutures] 요청/디코드 예외', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * React Router turbo-stream 디코드 — streamController.enqueue("…") 페이로드는
     * 평탄화된 참조 배열(값이 배열 인덱스, "_N" 키는 arr[N]이 실제 키, 음수는 undefined 류).
     */
    private function decodeContents(string $html): array
    {
        $payload = $this->extractStreamPayload($html);
        if ($payload === '') {
            return [];
        }

        $decodedString = json_decode('"'.$payload.'"'); // JS 문자열 이스케이프 해제
        if (! is_string($decodedString)) {
            return [];
        }

        $arr = json_decode($decodedString, true);
        if (! is_array($arr) || $arr === []) {
            return [];
        }

        $root = $this->resolveRef($arr, 0, 0);
        $contents = $root['loaderData']['routes/futures']['contents'] ?? [];

        return is_array($contents) ? $contents : [];
    }

    /**
     * enqueue("…") 페이로드를 수동 스캔으로 추출 — 대용량(45KB+) 문자열에서
     * 정규식 백트래킹 한도를 넘지 않도록 이스케이프를 직접 추적한다. 여러 청크는 이어붙인다.
     */
    private function extractStreamPayload(string $html): string
    {
        $marker = 'streamController.enqueue("';
        $payload = '';
        $offset = 0;

        while (($pos = strpos($html, $marker, $offset)) !== false) {
            $start = $pos + strlen($marker);
            $i = $start;
            $len = strlen($html);
            while ($i < $len) {
                $ch = $html[$i];
                if ($ch === '\\') {
                    $i += 2; // 이스케이프 시퀀스 통째로 건너뜀

                    continue;
                }
                if ($ch === '"') {
                    break; // 이스케이프되지 않은 종료 따옴표
                }
                $i++;
            }
            $payload .= substr($html, $start, $i - $start);
            $offset = $i + 1;
        }

        return $payload;
    }

    /** 참조 배열에서 인덱스 $i 의 값을 재귀 복원한다. */
    private function resolveRef(array $arr, int|float $i, int $depth): mixed
    {
        if ($depth > 48 || $i < 0) {
            return null; // 음수 = turbo-stream 특수값(undefined 등)
        }
        $v = $arr[(int) $i] ?? null;

        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(
                    fn ($x) => is_int($x) || is_float($x) ? $this->resolveRef($arr, $x, $depth + 1) : $x,
                    $v,
                );
            }
            $out = [];
            foreach ($v as $k => $val) {
                $key = str_starts_with((string) $k, '_') ? ($arr[(int) substr((string) $k, 1)] ?? $k) : $k;
                $out[$key] = is_int($val) || is_float($val) ? $this->resolveRef($arr, $val, $depth + 1) : $val;
            }

            return $out;
        }

        return $v;
    }

    /* ---------------------------------- 배너(모집중 학생) ---------------------------------- */

    /** @return list<string> 저장한 배너 external_key 목록(종료분은 저장 안 함) */
    private function upsertBanners(Game $game, array $c, Carbon $now): array
    {
        $recruitments = array_filter((array) ($c['recruitments'] ?? []), 'is_array');
        if ($recruitments === []) {
            return [];
        }

        // 같은 컨텐츠 안에서도 모집 기간이 다를 수 있어 (since, until) 단위로 배너를 만든다
        $groups = collect($recruitments)->groupBy(fn (array $r) => ($r['since'] ?? '').'|'.($r['until'] ?? ''));

        $keys = [];
        $i = 0;
        foreach ($groups as $group) {
            $i++;
            $since = $this->ts($group[0]['since'] ?? null) ?? $this->ts($c['startAt'] ?? null);
            $until = $this->ts($group[0]['until'] ?? null) ?? $this->ts($c['endAt'] ?? null);
            if ($since === null || ($until !== null && $until->isBefore($now))) {
                continue; // 종료된 픽업은 표기하지 않는다
            }

            $featured = $group->map(fn (array $r) => [
                'external_key' => (string) ($r['favoriteKey'] ?? ($r['student']['uid'] ?? '')),
                'name' => $r['studentName'] ?? null,
                'rarity' => null, // ScheduleService 가 캐릭터 마스터(traits.star)로 보강
                'rerun' => (bool) ($r['rerun'] ?? false),
            ])->filter(fn (array $f) => $f['external_key'] !== '')->unique('external_key')->values();

            if ($featured->isEmpty()) {
                continue;
            }

            $key = 'ml-'.$c['uid'].($i > 1 ? "-{$i}" : '');
            $first = $featured[0]['name'] ?? '픽업';
            Banner::updateOrCreate(
                ['subculture_game_id' => $game->id, 'external_key' => $key],
                [
                    'scope' => $since->isAfter($now) ? 'forecast' : 'current',
                    'kind' => 'character',
                    'title' => $first.($featured->count() > 1 ? ' 외 '.($featured->count() - 1).'명' : ''),
                    'featured' => $featured->all(),
                    'starts_at' => $since,
                    'ends_at' => $until,
                    'source' => self::SOURCE,
                ],
            );
            $keys[] = $key;
        }

        return $keys;
    }

    /* ---------------------------------- 이벤트 ---------------------------------- */

    private function upsertEvent(Game $game, array $c, Carbon $now): ?string
    {
        $start = $this->ts($c['startAt'] ?? null);
        $end = $this->ts($c['endAt'] ?? null);
        if ($start === null || ($end !== null && $end->isBefore($now))) {
            return null; // 종료된 이벤트는 표기하지 않는다
        }

        $key = 'ml-'.$c['uid'];
        GameEvent::updateOrCreate(
            ['subculture_game_id' => $game->id, 'external_key' => $key],
            [
                'scope' => $start->isAfter($now) ? 'forecast' : 'current',
                'kind' => self::EVENT_KINDS[$c['contentType']] ?? 'event',
                'title' => (string) ($c['name'] ?? '이벤트'),
                'starts_at' => $start,
                'ends_at' => $end,
                'image_url' => $c['imageUrl'] ?? null,
                'source' => self::SOURCE,
            ],
        );

        return $key;
    }

    /* ---------------------------------- 레이드 일정 ---------------------------------- */

    /**
     * 레이드 일정 보강/선등록 — crawl-raids(mollulog) 가 쓰는 키(`total-assault-84` 등)와
     * 같은 키로 저장해 중복 없이 합류한다. 편성(parties)은 건드리지 않는다.
     */
    private function upsertRaid(Game $game, array $c, string $bossImageBase): bool
    {
        $ri = (array) $c['raidInfo'];
        $slug = self::RAID_SLUGS[$ri['raidType'] ?? ''] ?? null;
        $season = $ri['seasonIndex'] ?? null;
        if ($slug === null || $season === null) {
            return false;
        }

        $typeKr = self::RAID_TYPE_KR[$ri['raidType']] ?? '레이드';
        $bossKr = (string) ($c['name'] ?? ($ri['name'] ?? '보스'));
        $armors = collect((array) ($ri['defenseTypes'] ?? []))
            ->map(fn ($d) => is_array($d) ? array_filter([
                'type' => self::DEFENSE_KR[$d['defenseType'] ?? ''] ?? null,
                'difficulty' => self::DIFFICULTY_KR[$d['difficulty'] ?? ''] ?? null,
            ]) : [])
            ->filter(fn (array $a) => isset($a['type']))
            ->values()
            ->all();

        $raid = Raid::firstOrNew([
            'subculture_game_id' => $game->id,
            'external_key' => "{$slug}-{$season}",
        ]);

        $raid->tags = array_merge((array) $raid->tags, array_filter([
            'terrain' => self::TERRAIN_KR[$ri['terrain'] ?? ''] ?? null,
        ]), [
            // 카드 UI 용 확장 정보(중첩 키 — 태그 pill 렌더에서는 제외됨)
            'mollulog' => array_filter([
                'boss_image' => ! empty($ri['boss']) ? "{$bossImageBase}/{$ri['boss']}" : null,
                'season_index' => $season,
                'attack_type' => self::ATTACK_KR[$ri['attackType'] ?? ''] ?? null,
                'armors' => $armors ?: null,
            ]),
        ]);

        $raid->starts_at = $this->ts($c['startAt'] ?? null) ?? $raid->starts_at;
        $raid->ends_at = $this->ts($c['endAt'] ?? null) ?? $raid->ends_at;
        if (blank($raid->boss_name)) {
            $raid->boss_name = $bossKr;
        }
        if (! $raid->exists) {
            $raid->name = "{$typeKr} #{$season} - {$bossKr}";
            $raid->raid_type = $typeKr;
            $raid->source = self::SOURCE;
            $raid->source_url = 'https://mollulog.net/raids/'.$slug.'/'.$season;
        }
        $raid->save();

        return true;
    }

    private function ts(?string $iso): ?Carbon
    {
        try {
            return $iso !== null && $iso !== '' ? Carbon::parse($iso)->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
