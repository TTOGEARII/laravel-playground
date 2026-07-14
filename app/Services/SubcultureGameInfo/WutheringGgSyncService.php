<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\WikiEntry;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\YoutubeSearchClient;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * wuthering.gg(명조) 동기화 — SSR DOM 파싱(공개 API 없음, 브라우저 불필요).
 * 공명자 = Character(도감·보유) + 캐릭터 상세를 traits 에 담는다:
 *   tier(티어리스트)·각성 재료·최고 무기·에코 세트(소나타)·추천 스탯.
 * 팀 조합은 사이트에 없어 유튜브 검색으로 캐릭터별 영상(traits.comps)을 채운다(신규/미수집만).
 * 방어: 목록 0건이면 sync 스킵(마크업 변경 사고 시 데이터 보존).
 */
class WutheringGgSyncService
{
    use FetchesWebContent;

    private const SOURCE = 'wutheringgg';

    /**
     * 추천 스탯 섹션(.stats-look)이 SSR 에서 일본어로 내려오는 이슈 보정(닫힌 소어휘).
     */
    private const STAT_KR = [
        'HP' => 'HP', '攻撃力' => '공격력', '防御力' => '방어력',
        'クリティカル' => '크리티컬', 'クリティカルダメージ' => '크리티컬 피해',
        '共鳴効率' => '공명 효율', '共鳴解放ダメージアップ' => '공명 해방 피해 보너스',
        '共鳴スキルダメージアップ' => '공명 스킬 피해 보너스', '通常攻撃ダメージアップ' => '일반 공격 피해 보너스',
        '重撃ダメージアップ' => '강공격 피해 보너스', '回復効果アップ' => '치유 효과 보너스',
        '氷結ダメージアップ' => '응결 피해 보너스', '焦熱ダメージアップ' => '열용 피해 보너스',
        '電導ダメージアップ' => '전도 피해 보너스', '気動ダメージアップ' => '기동 피해 보너스',
        '回折ダメージアップ' => '회절 피해 보너스', '消滅ダメージアップ' => '인멸 피해 보너스',
    ];

    public function __construct(private YoutubeSearchClient $youtube) {}

    /** @return array{characters:int, comps:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.wutheringgg');
        $base = rtrim((string) $cfg['base'], '/');
        $delayMicros = ((int) ($cfg['fetch_delay_ms'] ?? 300)) * 1000;

        $cards = $this->characterCards($base);
        if ($cards === []) {
            Log::warning('[WutheringGg] 캐릭터 목록 0건 — sync 스킵');

            return ['characters' => 0, 'comps' => 0];
        }

        $tiers = $this->fetchTierList($base); // slug => 'S'
        $count = 0;
        $compCount = 0;

        foreach ($cards as $card) {
            $slug = $card['slug'];
            $char = Character::firstOrNew(['subculture_game_id' => $game->id, 'external_key' => $slug]);
            $traits = (array) ($char->traits ?? []);

            $char->name = $card['name'];
            if (($card['rarity'] ?? null) !== null) {
                $char->rarity = $card['rarity'];
            }

            // 상세(티어·재료·무기·에코세트·스탯 + 전신 초상)는 매 sync 갱신(현재 메타 반영)
            $detail = $this->characterDetail($base, $slug);
            usleep($delayMicros);

            // 이미지는 상세의 전신 초상 우선(목록 head 아이콘은 일부 404)
            $image = $detail['portrait'] ?? $card['image'] ?? null;
            if ($image !== null) {
                $char->image_url = $image;
            }
            unset($detail['portrait']);

            // 팀 조합 영상은 없을 때만(유튜브 요청 절약 — 메타 갱신은 수동 재수집)
            $comps = $traits['comps'] ?? null;
            if (! is_array($comps) || $comps === []) {
                $comps = $this->comps($cfg, $card['name']);
                if ($comps !== []) {
                    $compCount++;
                }
            }

            $char->traits = array_merge($traits, array_filter([
                'element' => $card['element'] ?? ($traits['element'] ?? null),
                'weapon' => $card['weapon'] ?? ($traits['weapon'] ?? null),
                'tier' => $tiers[$slug] ?? ($traits['tier'] ?? null),
            ], fn ($v) => $v !== null), $detail, ['comps' => $comps]);

            if (! $char->exists) {
                $char->source = self::SOURCE;
                $char->active_flg = true;
            }
            $char->save();
            $count++;
        }

        // 옛 무기 위키(wiki-dex) 데이터 정리 — 명조는 캐릭터 정보만 표시(사용자 결정)
        WikiEntry::forGame($game->id)->where('source', self::SOURCE)->delete();

        return ['characters' => $count, 'comps' => $compCount];
    }

    /* ---------------------------------- 캐릭터 목록 ---------------------------------- */

    /** @return array<int, array{slug:string,name:string,element:?string,weapon:?string,rarity:?string,image:?string}> */
    private function characterCards(string $base): array
    {
        $html = $this->getHtml("{$base}/ko/characters");
        if ($html === null) {
            return [];
        }

        $out = [];
        // 카드: <a href="/ko/characters/{slug}">[속성 title][무기 title][초상 img][이름][★들]</a>
        preg_match_all('/<a[^>]+href="\/ko\/characters\/([a-z0-9-]+)"[^>]*>(.*?)<\/a>/s', $html, $cards, PREG_SET_ORDER);
        foreach ($cards as [$_, $slug, $body]) {
            $name = $this->firstMatch('/<div class="name"[^>]*>([^<]+)</u', $body);
            if ($name === null) {
                continue;
            }
            $stars = substr_count($body, '>★<');
            $image = $this->firstMatch('/<img[^>]+src="([^"]+iconrolehead[^"]+)"/u', $body);
            $out[$slug] = [
                'slug' => $slug,
                'name' => trim($name),
                'element' => $this->firstMatch('/class="badge elm[^"]*" title="([^"]+)"/u', $body),
                'weapon' => $this->firstMatch('/class="badge weapon" title="([^"]+)"/u', $body),
                'rarity' => $stars > 0 ? "{$stars}성" : null,
                'image' => $image !== null ? $base.html_entity_decode($image) : null,
            ];
        }

        return array_values($out);
    }

    /* ---------------------------------- 티어리스트 ---------------------------------- */

    /** @return array<string, string> slug => 티어 등급(S/A/B/C/D) */
    private function fetchTierList(string $base): array
    {
        $html = $this->getHtml("{$base}/ko/tier-list");
        if ($html === null) {
            return [];
        }

        $xp = $this->xpath($html);
        if ($xp === null) {
            return [];
        }

        $tiers = [];
        // <div class="tier-list"> <div class="tier S"> <a href="/ko/characters/{slug}">
        foreach ($this->byClass($xp, 'tier') as $tierEl) {
            $cls = $tierEl instanceof DOMNode ? (string) $tierEl->attributes?->getNamedItem('class')?->nodeValue : '';
            if (! preg_match('/\btier\s+([SABCD])\b/', $cls, $m)) {
                continue;
            }
            foreach ($xp->query('.//a[contains(@href, "/ko/characters/")]', $tierEl) as $a) {
                $href = (string) $a->attributes?->getNamedItem('href')?->nodeValue;
                if (preg_match('#/ko/characters/([a-z0-9-]+)#', $href, $hm)) {
                    $tiers[$hm[1]] ??= $m[1]; // 여러 역할 목록에 중복 등장 시 첫(상위) 등급 유지
                }
            }
        }

        return $tiers;
    }

    /* ---------------------------------- 캐릭터 상세 ---------------------------------- */

    /**
     * 캐릭터 빌드 페이지 → 상세 필드(재료·무기·에코세트·스탯). 실패/누락은 해당 키 생략.
     *
     * @return array<string, mixed>
     */
    private function characterDetail(string $base, string $slug): array
    {
        $html = $this->getHtml("{$base}/ko/characters/{$slug}");
        if ($html === null) {
            return [];
        }
        $xp = $this->xpath($html);
        if ($xp === null) {
            return [];
        }

        return array_filter([
            'portrait' => $this->parsePortrait($html),
            'materials' => $this->parseMaterials($xp),
            'best_weapon' => $this->parseWeapon($xp),
            'echo_sets' => $this->parseEchoSets($xp),
            'best_stats' => $this->parseBestStats($xp),
        ], fn ($v) => ! empty($v));
    }

    /** 상세 페이지의 전신 초상(iconrolepile) — 목록 head 아이콘(일부 404)보다 크고 안정적. */
    private function parsePortrait(string $html): ?string
    {
        if (preg_match('#(/_ipx/[^"\'\s]*iconrolepile[^"\'\s]+\.png)#i', $html, $m)) {
            return 'https://wuthering.gg'.html_entity_decode($m[1]);
        }

        return null;
    }

    /** 각성 재료: .ascension li.consume → {name, cost}. */
    private function parseMaterials(DOMXPath $xp): array
    {
        $asc = $this->byClass($xp, 'ascension')->item(0);
        if ($asc === null) {
            return [];
        }
        $out = [];
        foreach ($xp->query('.//li[contains(@class, "consume")]', $asc) as $li) {
            $name = $this->text($this->byClass($xp, 'name', $li)->item(0));
            if ($name === '') {
                continue;
            }
            $out[] = array_filter([
                'name' => $name,
                'cost' => $this->text($this->byClass($xp, 'cost', $li)->item(0)),
            ], fn ($v) => $v !== '');
        }

        return $out;
    }

    /** 최고 무기: .character-weapon .weapon-info → {name, stats:[{k,v}], ability}. */
    private function parseWeapon(DOMXPath $xp): array
    {
        $wi = $this->byClass($xp, 'weapon-info')->item(0);
        if ($wi === null) {
            return [];
        }
        $name = $this->text($this->byClass($xp, 'name', $wi)->item(0));
        if ($name === '') {
            return [];
        }

        $stats = [];
        $statBox = $this->byClass($xp, 'weapon-stats', $wi)->item(0);
        if ($statBox !== null) {
            foreach ($xp->query('.//div[contains(@class, "item")]', $statBox) as $item) {
                $k = $this->text($this->byClass($xp, 'text', $item)->item(0));
                $v = $this->text($this->byClass($xp, 'value', $item)->item(0));
                if ($k !== '' && $v !== '') {
                    $stats[] = ['k' => $k, 'v' => $v];
                }
            }
        }

        // 무기 효과(있으면 요약)
        $abilityNode = $this->byClass($xp, 'weapon-ability')->item(0);
        $ability = $abilityNode !== null ? mb_substr($this->text($abilityNode), 0, 400) : '';

        return array_filter([
            'name' => $name,
            'stats' => $stats,
            'ability' => $ability,
        ], fn ($v) => ! empty($v));
    }

    /**
     * 에코 세트: 추천 빌드(.character-echoes .echoes-build) 각 피스의 소나타(.fet img alt)를 집계.
     * → [{sonata, count}] (예: 냉철한 결단 5). 5+3+... 하이브리드도 count 로 표현.
     */
    private function parseEchoSets(DOMXPath $xp): array
    {
        $ce = $this->byClass($xp, 'character-echoes')->item(0);
        if ($ce === null) {
            return [];
        }
        $build = $this->byClass($xp, 'echoes-build', $ce)->item(0) ?? $ce;

        $counts = [];
        foreach ($this->byClass($xp, 'fet', $build) as $fet) {
            foreach ($xp->query('.//img', $fet) as $img) {
                $sonata = trim((string) $img->attributes?->getNamedItem('alt')?->nodeValue);
                if ($sonata !== '') {
                    $counts[$sonata] = ($counts[$sonata] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return collect($counts)->map(fn ($count, $sonata) => ['sonata' => $sonata, 'count' => $count])->values()->all();
    }

    /** 추천 스탯 우선순위: .stats-look li.stat (SSR 일본어 → 한글 매핑). */
    private function parseBestStats(DOMXPath $xp): array
    {
        $box = $this->byClass($xp, 'stats-look')->item(0);
        if ($box === null) {
            return [];
        }
        $out = [];
        foreach ($xp->query('.//li[contains(@class, "stat")]', $box) as $li) {
            $raw = $this->text($li);
            if ($raw === '') {
                continue;
            }
            // "攻撃力%" 처럼 % 접미사가 붙는 경우 기준어를 매핑하고 % 를 유지
            $pct = str_ends_with($raw, '%');
            $base = $pct ? rtrim($raw, '%') : $raw;
            $out[] = (self::STAT_KR[$base] ?? $base).($pct ? '%' : '');
        }

        return array_values(array_unique($out));
    }

    /* ---------------------------------- 팀 조합(유튜브) ---------------------------------- */

    /** @return array<int, array{title:string,url:string,thumbnail:string}> */
    private function comps(array $cfg, string $name): array
    {
        $c = (array) ($cfg['comps'] ?? []);
        if (! ($c['enabled'] ?? true)) {
            return [];
        }
        $query = trim("명조 {$name} ".($c['query_suffix'] ?? '추천 조합'));
        $videos = $this->youtube->search($query, (int) ($c['limit'] ?? 4));

        return collect($videos)->map(fn (array $v) => [
            'title' => $v['title'],
            'url' => $v['url'],
            'thumbnail' => "https://i.ytimg.com/vi/{$v['video_id']}/mqdefault.jpg",
        ])->all();
    }

    /* ---------------------------------- DOM 헬퍼 ---------------------------------- */

    private function xpath(string $html): ?DOMXPath
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return $ok ? new DOMXPath($doc) : null;
    }

    /** 특정 클래스 토큰을 정확히 가진 엘리먼트(부분일치 오탐 방지). */
    private function byClass(DOMXPath $xp, string $class, ?DOMNode $ctx = null): \DOMNodeList
    {
        $q = ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";

        return $xp->query($q, $ctx);
    }

    private function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) === 1 ? $m[1] : null;
    }
}
