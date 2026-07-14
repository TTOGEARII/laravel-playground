<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use Illuminate\Support\Facades\Log;

/**
 * genshin-builds.com(한국어·SSR·멀티게임) 빌드 수집 — 호요버스 추천 무기·세트.
 * 호요버스엔 wuthering.gg 같은 통합 빌드 소스가 없어 명조 수준(추천무기·세트)을 이걸로 채운다.
 *   ① 목록에서 (한글명 → 슬러그) 매핑
 *   ② 각 캐릭터 빌드 페이지에서 추천 무기/세트를 랭킹 순으로 파싱
 *      - 순서: 앵커 href(/ko/…/{slug}) 등장 순
 *      - 이름: 앵커 img alt(스타레일·젠존제) 우선, 없으면 RSC 데이터 블롭(원신)
 * 결과 → Character.traits.rec_weapons / rec_sets → StudentDex 모달(추천 무기·세트).
 * 팀 조합은 마크업이 불안정해 제외(유튜브 조합 유지). 방어: 매핑/파싱 0건이면 스킵.
 */
class GenshinBuildsSyncService
{
    use FetchesWebContent;

    /** @return array{characters:int, weapons:int, sets:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.genshin_builds');
        $gameCfg = $cfg['games'][$game->slug] ?? null;
        if ($gameCfg === null) {
            Log::warning('[GBuilds] 미지원 게임', ['game' => $game->slug]);

            return ['characters' => 0, 'weapons' => 0, 'sets' => 0];
        }

        $base = rtrim((string) $cfg['base'], '/');
        $delayMicros = ((int) ($cfg['fetch_delay_ms'] ?? 300)) * 1000;

        $slugMap = $this->fetchSlugMap($base, (string) $gameCfg['list'], (string) $gameCfg['char_path']);
        if ($slugMap === []) {
            Log::warning('[GBuilds] 이름→슬러그 매핑 0건 — sync 스킵', ['game' => $game->slug]);

            return ['characters' => 0, 'weapons' => 0, 'sets' => 0];
        }

        $chars = Character::where('subculture_game_id', $game->id)->where('active_flg', true)->get();
        $wCount = 0;
        $sCount = 0;

        foreach ($chars as $char) {
            $slug = $slugMap[$this->norm($char->name)] ?? null;
            if ($slug === null) {
                continue; // 미출시/이름 불일치
            }

            $build = $this->fetchBuild($base.(string) $gameCfg['char_path'].$slug, $gameCfg, $cfg);
            usleep($delayMicros);
            if ($build === null) {
                continue;
            }

            $traits = (array) ($char->traits ?? []);
            $changed = false;
            if ($build['weapons'] !== []) {
                $traits['rec_weapons'] = $build['weapons'];
                $wCount++;
                $changed = true;
            }
            if ($build['sets'] !== []) {
                $traits['rec_sets'] = $build['sets'];
                $sCount++;
                $changed = true;
            }
            if ($changed) {
                $char->traits = $traits;
                $char->save();
            }
        }

        return ['characters' => $chars->count(), 'weapons' => $wCount, 'sets' => $sCount];
    }

    /* ---------------------------------- 이름→슬러그 매핑 ---------------------------------- */

    /**
     * 목록 페이지 → {정규화 한글명 => 슬러그}. 목록 구조가 게임마다 달라 세 패턴 모두 시도·병합한다.
     *   A(원신): <h3 sr-only>{이름}</h3><a href="{charPath}{slug}">
     *   B(스타레일): <a href="{charPath}{slug}">…<img alt="{이름}">
     *   C(젠존제): JSON 블롭 {"id":"{slug}","name":"{이름}"}
     * 미출시(영문명) 카드는 우리 DB 와 안 맞아 자연히 제외된다.
     */
    private function fetchSlugMap(string $base, string $listPath, string $charPath): array
    {
        $html = $this->getHtml($base.$listPath);
        if ($html === null) {
            return [];
        }
        $q = preg_quote($charPath, '#');
        $map = [];

        // A: sr-only h3 + 링크
        if (preg_match_all('#<h3[^>]*class="[^"]*sr-only[^"]*"[^>]*>([^<]+)</h3>\s*<a[^>]+href="'.$q.'(?:upcoming/)?([a-z0-9_-]+)"#u', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $this->putMap($map, $r[1], $r[2]);
            }
        }
        // B: 앵커 href 이후 img alt
        if (preg_match_all('#href="'.$q.'([a-z0-9_-]+)"[^>]*>(?:(?!</a>).){0,400}?alt="([^"]+)"#su', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                if (! preg_match('/^[a-z0-9_\s-]+$/', $r[2])) { // 영문 코드 alt 제외
                    $this->putMap($map, $r[2], $r[1]);
                }
            }
        }
        // C: JSON 블롭 {"id":"slug","name":"이름"} (원신 RSC 블롭의 [0,..] 형식과 구분됨)
        if (preg_match_all('#\{"id":"([a-z0-9_-]+)","name":"([^"]+)"#u', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $this->putMap($map, $r[2], $r[1]);
            }
        }

        return $map;
    }

    private function putMap(array &$map, string $name, string $slug): void
    {
        $key = $this->norm(html_entity_decode($name));
        if ($key !== '') {
            $map[$key] ??= $slug;
        }
    }

    /* ---------------------------------- 빌드(무기·세트) ---------------------------------- */

    /** @return array{weapons: list<string>, sets: list<string>}|null */
    private function fetchBuild(string $url, array $gameCfg, array $cfg): ?array
    {
        $html = $this->getHtml($url);
        if ($html === null) {
            return null;
        }
        $decoded = html_entity_decode($html);
        // 원신용 이름 블롭: {"id":[0,"slug"],"name":[0,"이름"]}
        $blob = [];
        if (preg_match_all('/\{"id":\[0,"([a-z0-9_-]+)"\],"name":\[0,"([^"]+)"\]/', $decoded, $bm, PREG_SET_ORDER)) {
            foreach ($bm as $b) {
                $blob[$b[1]] = $b[2];
            }
        }

        return [
            'weapons' => $this->orderedNames($html, $blob, '/ko'.$gameCfg['weapon'], (int) ($cfg['top_weapons'] ?? 5)),
            'sets' => $this->orderedNames($html, $blob, '/ko'.$gameCfg['set'], (int) ($cfg['top_sets'] ?? 4)),
        ];
    }

    /**
     * 링크 등장 순(랭킹)으로 이름 목록 — 앵커 img alt 우선, 없으면 블롭.
     *
     * @param  array<string,string>  $blob  slug → 이름
     * @return list<string>
     */
    private function orderedNames(string $html, array $blob, string $linkPrefix, int $limit): array
    {
        $slugs = [];
        if (preg_match_all('#href="'.preg_quote($linkPrefix, '#').'([a-z0-9_-]+)"#', $html, $m)) {
            foreach ($m[1] as $slug) {
                if (! in_array($slug, $slugs, true)) {
                    $slugs[] = $slug;
                }
            }
        }

        $out = [];
        foreach (array_slice($slugs, 0, $limit) as $slug) {
            $name = $this->nameForLink($html, $linkPrefix, $slug) ?? ($blob[$slug] ?? null);
            if ($name !== null && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** 앵커(href) 이후 같은 링크 내 첫 img alt — 슬러그처럼 보이면(영문 코드) 무시. */
    private function nameForLink(string $html, string $linkPrefix, string $slug): ?string
    {
        $pattern = '#href="'.preg_quote($linkPrefix.$slug, '#').'"[^>]*>(?:(?!</a>).){0,400}?alt="([^"]+)"#su';
        if (preg_match($pattern, $html, $m)) {
            $alt = $this->cleanName(html_entity_decode($m[1]));
            if ($alt !== '' && ! preg_match('/^[a-z0-9_-]+$/', $alt)) {
                return $alt;
            }
        }

        return null;
    }

    /* ---------------------------------- 헬퍼 ---------------------------------- */

    private function cleanName(string $s): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s) ?? '');
    }

    /** 이름 매칭용 정규화(공백·중점·괄호·꺾쇠 제거). */
    private function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/[\s\x{00A0}·•\-_.「」()（）:]+/u', '', $s) ?? '');
    }
}
