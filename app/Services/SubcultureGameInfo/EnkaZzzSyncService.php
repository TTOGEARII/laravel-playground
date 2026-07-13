<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 젠레스 존 제로 캐릭터(에이전트) 도감 동기화 — Enka Network 스토어 데이터(GitHub raw, 정적 JSON).
 * avatars.json(에이전트 마스터) + locs.json(로컬라이즈) → Character. Name 코드가 locs.{lang} 의 키라
 * 한글명을 바로 해석한다(예: Avatar_Female_Size01_Corin → 코린). external_key=에이전트 id.
 * traits: element(속성)·profession(특성). rarity(S/A)는 rarity 컬럼. 수집 0건이면 sync 스킵.
 */
class EnkaZzzSyncService
{
    private const SOURCE = 'enka';

    /** @return int 동기화한 에이전트 수 */
    public function sync(Game $game): int
    {
        $cfg = (array) config('subculture-game-info.raids.enka');
        $gameCfg = $cfg['games'][$game->slug] ?? null;
        if ($gameCfg === null) {
            Log::warning('[Enka] 미지원 게임', ['game' => $game->slug]);

            return 0;
        }

        $timeout = (int) ($cfg['timeout'] ?? 20);
        $avatars = $this->getJson((string) $gameCfg['avatars_url'], $timeout);
        $locs = $this->getJson((string) $gameCfg['locs_url'], $timeout) ?? [];

        if (empty($avatars)) {
            Log::warning('[Enka] 에이전트 수집 0건 — sync 스킵', ['game' => $game->slug]);

            return 0;
        }

        $names = (array) ($locs[$gameCfg['lang'] ?? 'ko'] ?? []);
        $imageBase = rtrim((string) ($gameCfg['image_base'] ?? ''), '/');

        $count = 0;
        foreach ($avatars as $id => $a) {
            if (! is_array($a) || ! isset($a['Name'])) {
                continue;
            }
            $name = $names[$a['Name']] ?? null;
            if ($name === null || $name === '') {
                continue; // 한글명 못 얻는 항목(미출시/내부용)은 스킵
            }

            $traits = array_filter([
                'element' => $a['ElementTypes'][0] ?? null,
                'profession' => $a['ProfessionType'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $char = Character::firstOrNew(['subculture_game_id' => $game->id, 'external_key' => (string) $id]);
            $char->traits = array_merge((array) ($char->traits ?? []), $traits);
            $char->name = (string) $name;
            $char->rarity = ($a['Rarity'] ?? null) === 4 ? 'S' : (($a['Rarity'] ?? null) === 3 ? 'A' : null);
            if (! $char->exists) {
                $char->source = self::SOURCE;
                $char->active_flg = true;
            }
            if (! empty($a['Image']) && $imageBase !== '') {
                $char->image_url = $imageBase.$a['Image'];
            }
            $char->save();
            $count++;
        }

        return $count;
    }

    private function getJson(string $url, int $timeout): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('subculture-game-info.http.user_agent')])
                ->timeout($timeout)->get($url);

            if (! $res->ok()) {
                Log::warning('[Enka] HTTP 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            $json = $res->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('[Enka] 요청 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
