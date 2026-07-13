<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SchaleDB(블아 정보 소스) 동기화 — 정적 JSON over HTTP(Playwright·Gemini 불필요).
 * students → Character traits 도감 필드 보강(external_key=SchaleDB Id 라 기존 mollulog 행과 일치).
 * 일정(배너·이벤트)은 KR 기준이 정확한 몰루로그 미래시(MollulogFuturesSyncService)가 단일 출처라 여기서 다루지 않는다.
 * 방어: 수집 0건이면 sync 스킵.
 */
class SchaleDbSyncService
{
    private const SOURCE = 'schaledb';

    /** @return array{students:int} */
    public function sync(Game $game): array
    {
        $cfg = $this->config();
        $base = rtrim((string) $cfg['base'], '/');
        $lang = $cfg['lang'] ?? 'kr';

        $students = $this->getJson("{$base}/data/{$lang}/students.min.json");
        $loc = $this->getJson("{$base}/data/{$lang}/localization.min.json") ?? [];

        if (empty($students)) {
            Log::warning('[SchaleDB] students 수집 0건 — sync 스킵', ['game' => $game->slug]);

            return ['students' => 0];
        }

        return ['students' => $this->syncStudents($game, $students, $loc, $base)];
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
}
