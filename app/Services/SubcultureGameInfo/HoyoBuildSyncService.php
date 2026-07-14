<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\YoutubeSearchClient;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * 호요버스 캐릭터 빌드 보강 — 명조처럼 티어·조합·성장재료를 캐릭터(Character.traits)에 추가한다.
 * 호요버스엔 wuthering.gg 같은 통합 빌드 소스가 없어 확보 가능한 만큼만:
 *   comps    : 유튜브 검색('{prefix} {캐릭터} {suffix}') — 3게임 전부(신규/미수집만).
 *   tier     : SSR 티어리스트에서 이름 매칭 — 젠존제(zzz.gg)만 확보.
 *   materials: Project Yatta 캐릭터 상세의 ascension(강화 재료) — 원신·스타레일(젠존제는 Yatta 미지원).
 * 추천 무기(광추/W엔진)는 "이 캐릭터 베스트" 추천 소스가 없어 미제공. 스킬·이야기는 호요랩 위키 상세.
 */
class HoyoBuildSyncService
{
    use FetchesWebContent;

    public function __construct(private YoutubeSearchClient $youtube) {}

    /** @return array{characters:int, comps:int, tiers:int, materials:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.hoyo_build');
        $compCfg = $cfg['comps'][$game->slug] ?? null;
        $tierUrl = $cfg['tier'][$game->slug] ?? null;
        $matCfg = $cfg['materials'][$game->slug] ?? null;
        $delayMicros = ((int) ($cfg['fetch_delay_ms'] ?? 300)) * 1000;

        $tierMap = $tierUrl !== null ? $this->fetchTierList($tierUrl, (int) ($cfg['timeout'] ?? 20)) : [];
        // 스타레일은 재료 이름을 별도 item 목록으로 해결(원신은 avatar 응답 인라인)
        $itemDict = ($matCfg && ($matCfg['items'] ?? '') === 'bulk') ? $this->fetchItemDict($matCfg['base']) : null;

        $chars = Character::where('subculture_game_id', $game->id)->where('active_flg', true)->get();
        $compCount = 0;
        $tierCount = 0;
        $matCount = 0;

        foreach ($chars as $char) {
            $traits = (array) ($char->traits ?? []);
            $changed = false;

            // 티어: 이름 정규화 매칭
            if ($tierMap !== []) {
                $tier = $tierMap[$this->norm($char->name)] ?? null;
                if ($tier !== null && ($traits['tier'] ?? null) !== $tier) {
                    $traits['tier'] = $tier;
                    $tierCount++;
                    $changed = true;
                }
            }

            // 성장 재료: 없을 때만 Yatta 상세 조회(재료는 안정적이라 재수집 불필요)
            if ($matCfg !== null && empty($traits['materials'])) {
                $mats = $this->materials($matCfg, (string) $char->external_key, $itemDict, (int) ($cfg['materials_limit'] ?? 6));
                if ($mats !== []) {
                    $traits['materials'] = $mats;
                    $matCount++;
                    $changed = true;
                    usleep($delayMicros);
                }
            }

            // 조합: 없을 때만 유튜브 검색(요청 절약)
            if ($compCfg !== null && empty($traits['comps'])) {
                $comps = $this->comps($compCfg, $char->name, (int) ($cfg['comps_limit'] ?? 4));
                if ($comps !== []) {
                    $traits['comps'] = $comps;
                    $compCount++;
                    $changed = true;
                    usleep($delayMicros);
                }
            }

            if ($changed) {
                $char->traits = $traits;
                $char->save();
            }
        }

        return ['characters' => $chars->count(), 'comps' => $compCount, 'tiers' => $tierCount, 'materials' => $matCount];
    }

    /* ---------------------------------- 성장 재료(Yatta) ---------------------------------- */

    /** 게임 전체 아이템 목록(id → {name, rank}) — 스타레일 재료 이름 해결용(1회 캐시). */
    private function fetchItemDict(string $base): array
    {
        $json = $this->getJson(rtrim($base, '/').'/api/v2/kr/item');
        $items = data_get($json, 'data.items', []);
        $dict = [];
        foreach ((array) $items as $id => $it) {
            if (is_array($it) && ! empty($it['name'])) {
                $dict[(string) $id] = ['name' => $it['name'], 'rank' => (int) ($it['rank'] ?? 0)];
            }
        }

        return $dict;
    }

    /**
     * 캐릭터 강화 재료 — avatar/{id} 의 ascension(재료id → 총 개수)을 이름 해결·정리.
     * 등급 높은 대표 재료 위주로(가루/조각/덩이 같은 하위 티어는 최상위만), 상한 limit.
     *
     * @param  array<string, array{name:string,rank:int}>|null  $itemDict  bulk 모드 아이템 사전
     * @return array<int, array{name:string,cost:int}>
     */
    private function materials(array $matCfg, string $externalKey, ?array $itemDict, int $limit): array
    {
        $json = $this->getJson(rtrim($matCfg['base'], '/')."/api/v2/kr/avatar/{$externalKey}");
        $ascension = data_get($json, 'data.ascension');
        if (! is_array($ascension) || $ascension === []) {
            return [];
        }
        $inlineItems = ($matCfg['items'] ?? '') === 'inline' ? (array) data_get($json, 'data.items', []) : [];

        $resolved = [];
        foreach ($ascension as $id => $count) {
            $meta = $itemDict[(string) $id] ?? null;
            if ($meta === null && isset($inlineItems[(string) $id]) && is_array($inlineItems[(string) $id])) {
                $meta = ['name' => $inlineItems[(string) $id]['name'] ?? '', 'rank' => (int) ($inlineItems[(string) $id]['rank'] ?? 0)];
            }
            if ($meta === null || ($meta['name'] ?? '') === '') {
                continue;
            }
            $resolved[] = ['name' => (string) $meta['name'], 'cost' => (int) $count, 'rank' => (int) $meta['rank']];
        }

        // 가루/조각/덩이 등 하위 티어는 같은 계열의 최상위(등급 높은)만 남긴다.
        $byFamily = [];
        foreach ($resolved as $r) {
            $family = preg_replace('/\s*(가루|조각|덩이|부스러기|파편)\s*$/u', '', $r['name']);
            if (! isset($byFamily[$family]) || $r['rank'] > $byFamily[$family]['rank']) {
                $byFamily[$family] = $r;
            }
        }

        return collect($byFamily)
            ->sortByDesc('rank')
            ->take($limit)
            ->map(fn ($r) => ['name' => $r['name'], 'cost' => $r['cost']])
            ->values()
            ->all();
    }

    /* ---------------------------------- 티어리스트(SSR) ---------------------------------- */

    /** @return array<string, string> 정규화된 캐릭터명 => 티어 등급(S/A/B/C/D) */
    private function fetchTierList(string $url, int $timeout): array
    {
        $html = $this->getHtml($url);
        if ($html === null) {
            Log::warning('[HoyoBuild] 티어리스트 수집 실패', ['url' => $url]);

            return [];
        }

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $tiers = [];
        // .tier-list .tier.{S/A/B/C/D} → 내부 .name (캐릭터명)
        $tierEls = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' tier ')]");
        foreach ($tierEls as $tierEl) {
            $cls = $tierEl instanceof DOMNode ? (string) $tierEl->attributes?->getNamedItem('class')?->nodeValue : '';
            if (! preg_match('/\btier\s+([SABCD]\+?)\b/', $cls, $m)) {
                continue;
            }
            $grade = $m[1];
            foreach ($xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' name ')]", $tierEl) as $nameEl) {
                $name = trim(preg_replace('/\s+/u', ' ', $nameEl->textContent) ?? '');
                if ($name !== '') {
                    $tiers[$this->norm($name)] ??= $grade;
                }
            }
        }

        return $tiers;
    }

    /* ---------------------------------- 조합(유튜브) ---------------------------------- */

    /** @return array<int, array{title:string,url:string,thumbnail:string}> */
    private function comps(array $compCfg, string $name, int $limit): array
    {
        $query = trim(($compCfg['prefix'] ?? '')." {$name} ".($compCfg['suffix'] ?? '추천 조합'));
        $videos = $this->youtube->search($query, $limit);

        return collect($videos)->map(fn (array $v) => [
            'title' => $v['title'],
            'url' => $v['url'],
            'thumbnail' => "https://i.ytimg.com/vi/{$v['video_id']}/mqdefault.jpg",
        ])->all();
    }

    /** 이름 매칭용 정규화(공백·중점·괄호·꺾쇠 제거). */
    private function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/[\s\x{00A0}·•\-_.「」()（）]+/u', '', $s) ?? '');
    }
}
