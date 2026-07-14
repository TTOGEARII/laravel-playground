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
 * 호요버스 캐릭터 빌드 보강 — 명조처럼 티어·조합을 캐릭터(Character.traits)에 추가한다.
 * 호요버스엔 wuthering.gg 같은 통합 빌드 소스가 없어 확보 가능한 만큼만:
 *   comps: 유튜브 검색('{prefix} {캐릭터} {suffix}') — 3게임 전부(신규/미수집만).
 *   tier : SSR 티어리스트에서 이름 매칭 — 젠존제(zzz.gg)만 확보.
 * 재료·스킬·이야기는 이미 호요랩 위키 상세(도감 모달)로 제공된다.
 */
class HoyoBuildSyncService
{
    use FetchesWebContent;

    public function __construct(private YoutubeSearchClient $youtube) {}

    /** @return array{characters:int, comps:int, tiers:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.hoyo_build');
        $compCfg = $cfg['comps'][$game->slug] ?? null;
        $tierUrl = $cfg['tier'][$game->slug] ?? null;

        $tierMap = $tierUrl !== null ? $this->fetchTierList($tierUrl, (int) ($cfg['timeout'] ?? 20)) : [];

        $chars = Character::where('subculture_game_id', $game->id)->where('active_flg', true)->get();
        $compCount = 0;
        $tierCount = 0;

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

            // 조합: 없을 때만 유튜브 검색(요청 절약)
            if ($compCfg !== null && empty($traits['comps'])) {
                $comps = $this->comps($compCfg, $char->name, (int) ($cfg['comps_limit'] ?? 4));
                if ($comps !== []) {
                    $traits['comps'] = $comps;
                    $compCount++;
                    $changed = true;
                    usleep(((int) ($cfg['fetch_delay_ms'] ?? 300)) * 1000);
                }
            }

            if ($changed) {
                $char->traits = $traits;
                $char->save();
            }
        }

        return ['characters' => $chars->count(), 'comps' => $compCount, 'tiers' => $tierCount];
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
