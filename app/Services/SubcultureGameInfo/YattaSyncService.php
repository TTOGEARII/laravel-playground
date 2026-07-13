<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Project Yatta(=Amber) 동기화 — 호요버스 캐릭터 도감(정적 JSON, Playwright·Gemini 불필요).
 *   원신: gi.yatta.moe/api/v2/{lang}/avatar (traits: star/element/weapon/region, 이미지=yatta assets)
 *   스타레일: sr.yatta.top/api/v2/{lang}/avatar (traits: star/path/element, 이미지=Mar-7th StarRailRes)
 * external_key=캐릭터 id. 영문 코드는 config student_schema labels 로 한글 표시. 수집 0건이면 sync 스킵.
 */
class YattaSyncService
{
    private const SOURCE = 'yatta';

    /** @return int 동기화한 캐릭터 수 */
    public function sync(Game $game): int
    {
        $cfg = (array) config('subculture-game-info.raids.yatta');
        $gameCfg = $cfg['games'][$game->slug] ?? null;
        if ($gameCfg === null) {
            Log::warning('[Yatta] 미지원 게임', ['game' => $game->slug]);

            return 0;
        }

        $base = rtrim((string) $gameCfg['base'], '/');
        $lang = $cfg['lang'] ?? 'kr';
        $data = $this->getJson("{$base}/api/v2/{$lang}/avatar", (int) ($cfg['timeout'] ?? 20));
        $items = $data['data']['items'] ?? null;

        if (empty($items) || ! is_array($items)) {
            Log::warning('[Yatta] 캐릭터 수집 0건 — sync 스킵', ['game' => $game->slug]);

            return 0;
        }

        $imageTemplate = (string) ($gameCfg['image_template'] ?? '');

        $count = 0;
        foreach ($items as $s) {
            if (! is_array($s)) {
                continue;
            }
            $id = (string) ($s['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $char = Character::firstOrNew(['subculture_game_id' => $game->id, 'external_key' => $id]);
            $char->traits = array_merge((array) ($char->traits ?? []), $this->traitsFor($game->slug, $s));
            $char->name = (string) ($s['name'] ?? $id); // yatta 한글명이 정본(호요는 다른 소스 없음)
            if (! $char->exists) {
                $char->source = self::SOURCE;
                $char->active_flg = true;
            }
            $image = $this->image($imageTemplate, $s);
            if ($image !== null) {
                $char->image_url = $image;
            }
            $char->save();
            $count++;
        }

        return $count;
    }

    /** 게임별 도감 traits 추출(원신=element/weapon/region, 스타레일=path/element). */
    private function traitsFor(string $slug, array $s): array
    {
        $traits = $slug === 'starrail'
            ? [
                'star' => $s['rank'] ?? null,
                'path' => $s['types']['pathType'] ?? null,
                'element' => $s['types']['combatType'] ?? null,
            ]
            : [
                'star' => $s['rank'] ?? null,
                'element' => $s['element'] ?? null,
                'weapon' => $s['weaponType'] ?? null,
                'region' => $s['region'] ?? null,
            ];

        return array_filter($traits, fn ($v) => $v !== null && $v !== '');
    }

    /** image_template 의 {icon}/{id} 치환. */
    private function image(string $template, array $s): ?string
    {
        if ($template === '') {
            return null;
        }

        return strtr($template, [
            '{icon}' => (string) ($s['icon'] ?? ''),
            '{id}' => (string) ($s['id'] ?? ''),
        ]);
    }

    private function getJson(string $url, int $timeout): ?array
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('subculture-game-info.http.user_agent')])
                ->timeout($timeout)->get($url);

            if (! $res->ok()) {
                Log::warning('[Yatta] HTTP 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            $json = $res->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::warning('[Yatta] 요청 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
