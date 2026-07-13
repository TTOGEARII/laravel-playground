<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Banner;
use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GameEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SchaleDB(블아 정보 소스) 동기화 — 정적 JSON over HTTP(Playwright·Gemini 불필요).
 *   students → Character traits 도감 필드 보강(external_key=SchaleDB Id 라 기존 mollulog 행과 일치)
 *   config   → Banner(모집중 학생)·GameEvent(진행중 이벤트·레이드 예고)
 * 지역 매핑: 현재=Global(KR 근사), 미래시=Jp(JP 서버가 앞서는 BA 미래시 관례).
 * 방어: 어떤 소스든 0건이면 해당 파트 sync 를 건너뛴다(마크업/네트워크 사고 시 데이터 보존).
 */
class SchaleDbSyncService
{
    private const SOURCE = 'schaledb';

    /** SchaleDB CurrentRaid.type → 한글 라벨. */
    private const RAID_TYPE_LABELS = [
        'Raid' => '총력전',
        'Elimination' => '대결전',
        'MultiFloorRaid' => '다층 레이드',
        'WorldRaid' => '월드 레이드',
        'InteractiveWorldRaid' => '월드 레이드',
    ];

    /** @return array{students:int,banners:int,events:int} */
    public function sync(Game $game): array
    {
        $cfg = $this->config();
        $base = rtrim((string) $cfg['base'], '/');
        $lang = $cfg['lang'] ?? 'kr';

        $students = $this->getJson("{$base}/data/{$lang}/students.min.json");
        $loc = $this->getJson("{$base}/data/{$lang}/localization.min.json") ?? [];
        $config = $this->getJson("{$base}/data/config.min.json");
        $raids = $this->getJson("{$base}/data/{$lang}/raids.min.json") ?? [];

        if (empty($students)) {
            Log::warning('[SchaleDB] students 수집 0건 — 전체 sync 스킵', ['game' => $game->slug]);

            return ['students' => 0, 'banners' => 0, 'events' => 0];
        }

        $studentCount = $this->syncStudents($game, $students, $loc, $base);
        $schedule = $this->syncSchedule($game, $students, $loc, $config, $raids, $base, $cfg);

        return ['students' => $studentCount] + $schedule;
    }

    private function config(): array
    {
        return (array) config('subculture-game-info.raids.schaledb');
    }

    private function getJson(string $url): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('subculture-game-info.http.user_agent')])
                ->timeout((int) ($this->config()['timeout'] ?? 20))
                ->get($url);

            if (! $res->ok()) {
                Log::warning('[SchaleDB] HTTP 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            $json = $res->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('[SchaleDB] 요청 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * students → Character traits 도감 필드 보강(멱등).
     * 기존 키(mollulog role 등)는 보존하고 도감 필드만 덮어쓴다. 새 학생은 생성.
     *
     * @param  array<string, array>  $students
     * @param  array<string, mixed>  $loc
     */
    private function syncStudents(Game $game, array $students, array $loc, string $base): int
    {
        $role = (array) ($loc['TacticRole'] ?? []);
        $school = (array) ($loc['School'] ?? []);
        $armor = (array) ($loc['ArmorType'] ?? []);
        $bullet = (array) ($loc['BulletType'] ?? []);
        $squad = (array) ($loc['SquadType'] ?? []);
        $pos = ['Front' => '전열', 'Middle' => '중열', 'Back' => '후열'];

        $count = 0;
        foreach ($students as $s) {
            if (! is_array($s)) {
                continue;
            }
            $id = (string) ($s['Id'] ?? '');
            if ($id === '') {
                continue;
            }

            $dex = array_filter([
                'star' => $s['StarGrade'] ?? null,
                'tactic' => $role[$s['TacticRole'] ?? ''] ?? ($s['TacticRole'] ?? null),
                'squad' => $squad[$s['SquadType'] ?? ''] ?? ($s['SquadType'] ?? null),
                'school' => $school[$s['School'] ?? ''] ?? ($s['School'] ?? null),
                'weapon' => $s['WeaponType'] ?? null,
                'bullet' => $bullet[$s['BulletType'] ?? ''] ?? ($s['BulletType'] ?? null),
                'armor' => $armor[$s['ArmorType'] ?? ''] ?? ($s['ArmorType'] ?? null),
                'position' => $pos[$s['Position'] ?? ''] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $char = Character::firstOrNew([
                'subculture_game_id' => $game->id,
                'external_key' => $id,
            ]);
            $char->traits = array_merge((array) ($char->traits ?? []), $dex);
            if (! $char->exists) {
                $char->name = (string) ($s['Name'] ?? $id);
                $char->source = self::SOURCE;
                $char->active_flg = true;
                $char->image_url = "{$base}/images/student/collection/{$id}.webp";
            }
            $char->save();
            $count++;
        }

        return $count;
    }

    /**
     * config.min.json → Banner + GameEvent. 각 scope(current/forecast)별로 갈아끼운다(멱등).
     *
     * @return array{banners:int,events:int}
     */
    private function syncSchedule(Game $game, array $students, array $loc, ?array $config, array $raids, string $base, array $cfg): array
    {
        $regions = $config['Regions'] ?? null;
        if (empty($regions) || ! is_array($regions)) {
            Log::warning('[SchaleDB] config Regions 없음 — 스케줄 sync 스킵', ['game' => $game->slug]);

            return ['banners' => 0, 'events' => 0];
        }

        $byName = collect($regions)->keyBy('Name');
        $eventNames = (array) ($loc['EventName'] ?? []);
        $raidNames = $this->raidNameIndex($raids);

        $scopes = ['current' => $cfg['region_current'] ?? 'Global', 'forecast' => $cfg['region_forecast'] ?? 'Jp'];

        $bannerKeys = [];
        $eventKeys = [];
        $banners = 0;
        $events = 0;

        foreach ($scopes as $scope => $regionName) {
            $region = $byName->get($regionName);
            if (! is_array($region)) {
                continue;
            }

            foreach ((array) ($region['CurrentGacha'] ?? []) as $g) {
                $key = $this->upsertBanner($game, $scope, $g, $students, $base);
                if ($key !== null) {
                    $bannerKeys[] = $key;
                    $banners++;
                }
            }

            foreach ((array) ($region['CurrentEvents'] ?? []) as $e) {
                $key = $this->upsertEvent($game, $scope, 'event', (string) ($eventNames[$e['event'] ?? ''] ?? ('이벤트 #'.($e['event'] ?? '?'))), $e['start'] ?? null, $e['end'] ?? null, "event-{$scope}-".($e['event'] ?? ''));
                if ($key !== null) {
                    $eventKeys[] = $key;
                    $events++;
                }
            }

            foreach ((array) ($region['CurrentRaid'] ?? []) as $r) {
                $typeLabel = self::RAID_TYPE_LABELS[$r['type'] ?? ''] ?? '레이드';
                $boss = $raidNames[$r['raid'] ?? ''] ?? null; // 보스명 알 때만 덧붙임(MultiFloor 등은 풀이 달라 없을 수 있음)
                $title = $boss !== null ? "{$typeLabel} · {$boss}" : $typeLabel;
                $key = $this->upsertEvent($game, $scope, 'raid', $title, $r['start'] ?? null, $r['end'] ?? null, "raid-{$scope}-".($r['type'] ?? '').'-'.($r['season'] ?? ($r['raid'] ?? '')));
                if ($key !== null) {
                    $eventKeys[] = $key;
                    $events++;
                }
            }
        }

        // 이번 sync 에서 안 본 (게임의 schaledb) 행은 지난 회차 — 정리(멱등 갱신)
        Banner::forGame($game->id)->where('source', self::SOURCE)->whereNotIn('external_key', $bannerKeys)->delete();
        GameEvent::forGame($game->id)->where('source', self::SOURCE)->whereNotIn('external_key', $eventKeys)->delete();

        return ['banners' => $banners, 'events' => $events];
    }

    /** @return string|null external_key(성공 시) */
    private function upsertBanner(Game $game, string $scope, array $gacha, array $students, string $base): ?string
    {
        $start = $gacha['start'] ?? null;
        if ($start === null) {
            return null;
        }
        $key = "gacha-{$scope}-{$start}";

        $featured = collect((array) ($gacha['characters'] ?? []))
            ->map(function ($id) use ($students, $base) {
                $s = $students[(string) $id] ?? null;

                return [
                    'external_key' => (string) $id,
                    'name' => $s['Name'] ?? (string) $id,
                    'rarity' => $s['StarGrade'] ?? null,
                    'image' => "{$base}/images/student/icon/{$id}.webp",
                ];
            })->values()->all();

        Banner::updateOrCreate(
            ['subculture_game_id' => $game->id, 'external_key' => $key],
            [
                'scope' => $scope,
                'kind' => 'character',
                'title' => count($featured) ? ($featured[0]['name'].(count($featured) > 1 ? ' 외 '.(count($featured) - 1).'명' : '')) : null,
                'featured' => $featured,
                'starts_at' => $this->ts($start),
                'ends_at' => $this->ts($gacha['end'] ?? null),
                'source' => self::SOURCE,
            ],
        );

        return $key;
    }

    private function upsertEvent(Game $game, string $scope, string $kind, string $title, $start, $end, string $keySuffix): ?string
    {
        if ($start === null) {
            return null;
        }
        $key = $keySuffix;

        GameEvent::updateOrCreate(
            ['subculture_game_id' => $game->id, 'external_key' => $key],
            [
                'scope' => $scope,
                'kind' => $kind,
                'title' => $title,
                'starts_at' => $this->ts($start),
                'ends_at' => $this->ts($end),
                'source' => self::SOURCE,
            ],
        );

        return $key;
    }

    /** raids.min.json 여러 풀(Raid/MultiFloorRaid/WorldRaid) → [id => 보스명]. */
    private function raidNameIndex(array $raids): array
    {
        $index = [];
        foreach (['Raid', 'MultiFloorRaid', 'WorldRaid', 'InteractiveWorldRaid'] as $pool) {
            foreach ((array) ($raids[$pool] ?? []) as $r) {
                if (is_array($r) && isset($r['Id'], $r['Name'])) {
                    $index[$r['Id']] = $r['Name'];
                }
            }
        }

        return $index;
    }

    private function ts($unix): ?Carbon
    {
        return $unix !== null ? Carbon::createFromTimestamp((int) $unix) : null;
    }
}
