<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\WikiEntry;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use Illuminate\Support\Facades\Log;

/**
 * wuthering.gg(명조) 동기화 — SSR DOM 파싱(API 없음).
 *   /ko/characters → Character(공명자 도감·보유: 이름/성급/속성/무기/초상)
 *   /ko/weapons    → WikiEntry(무기) + 무기별 상세 페이지(스탯·스킬·설정)까지 순회 수집
 * 방어: 목록 0건이면 해당 파트 스킵(마크업 변경 사고 시 데이터 보존).
 */
class WutheringGgSyncService
{
    use FetchesWebContent;

    private const SOURCE = 'wutheringgg';

    /** @return array{characters:int, weapons:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.wutheringgg');
        $base = rtrim((string) $cfg['base'], '/');
        $delayMicros = ((int) ($cfg['fetch_delay_ms'] ?? 300)) * 1000;

        return [
            'characters' => $this->syncCharacters($game, $base),
            'weapons' => $this->syncWeapons($game, $base, $delayMicros),
        ];
    }

    /* ---------------------------------- 캐릭터(공명자) ---------------------------------- */

    private function syncCharacters(Game $game, string $base): int
    {
        $html = $this->getHtml("{$base}/ko/characters");
        if ($html === null) {
            Log::warning('[WutheringGg] 캐릭터 목록 수집 실패');

            return 0;
        }

        $count = 0;
        // 카드: <a href="/ko/characters/{slug}" ...> [속성배지 title] [무기배지 title] [초상 img] [이름] [★들]
        preg_match_all('/<a[^>]+href="\/ko\/characters\/([a-z0-9-]+)"[^>]*>(.*?)<\/a>/s', $html, $cards, PREG_SET_ORDER);
        foreach ($cards as [$_, $slug, $body]) {
            $name = $this->firstMatch('/<div class="name"[^>]*>([^<]+)</u', $body);
            if ($name === null) {
                continue; // 네비게이션 등 카드가 아닌 링크
            }

            $element = $this->firstMatch('/class="badge elm[^"]*" title="([^"]+)"/u', $body);
            $weapon = $this->firstMatch('/class="badge weapon" title="([^"]+)"/u', $body);
            $image = $this->firstMatch('/<img[^>]+src="([^"]+iconrolehead[^"]+)"/u', $body);
            $stars = substr_count($body, '>★<');

            $char = Character::firstOrNew(['subculture_game_id' => $game->id, 'external_key' => $slug]);
            $char->name = trim($name);
            $char->rarity = $stars > 0 ? "{$stars}성" : $char->rarity;
            $char->traits = array_merge((array) ($char->traits ?? []), array_filter([
                'element' => $element,
                'weapon' => $weapon,
            ]));
            if ($image !== null) {
                $char->image_url = $base.html_entity_decode($image);
            }
            if (! $char->exists) {
                $char->source = self::SOURCE;
                $char->active_flg = true;
            }
            $char->save();
            $count++;
        }

        return $count;
    }

    /* ---------------------------------- 무기(위키 항목) ---------------------------------- */

    private function syncWeapons(Game $game, string $base, int $delayMicros): int
    {
        $html = $this->getHtml("{$base}/ko/weapons");
        if ($html === null) {
            Log::warning('[WutheringGg] 무기 목록 수집 실패');

            return 0;
        }

        preg_match_all('/<a[^>]+href="\/ko\/weapons\/([a-z0-9-]+)"[^>]*class="weapon quality(\d)"[^>]*>(.*?)<\/a>/s', $html, $cards, PREG_SET_ORDER);
        if ($cards === []) {
            Log::warning('[WutheringGg] 무기 카드 파싱 0건 — 마크업 변경 의심, 스킵');

            return 0;
        }

        $count = 0;
        $seen = [];
        foreach ($cards as [$_, $slug, $quality, $body]) {
            $name = $this->firstMatch('/<div class="name"[^>]*>([^<]+)</u', $body);
            if ($name === null) {
                continue;
            }
            $image = $this->firstMatch('/<img[^>]+src="([^"]+)"/u', $body);

            $entry = WikiEntry::firstOrNew([
                'subculture_game_id' => $game->id,
                'source' => self::SOURCE,
                'menu_key' => 'weapons',
                'external_key' => $slug,
            ]);
            $entry->menu_label = '무기';
            $entry->name = trim($name);
            $entry->icon_url = $image !== null ? $base.html_entity_decode($image) : null;
            $entry->filters = [['value' => "★{$quality}"]];

            // 상세는 신규/미수집만(121건 재수집 방지)
            if ($entry->detail === null) {
                $entry->detail = $this->weaponDetail($base, $slug);
                usleep($delayMicros);
            }

            $entry->save();
            $seen[] = $slug;
            $count++;
        }

        WikiEntry::forGame($game->id)->where('source', self::SOURCE)
            ->where('menu_key', 'weapons')->whereNotIn('external_key', $seen)->delete();

        return $count;
    }

    /** 무기 상세 페이지(SSR 텍스트) → 스탯/스킬/설정 섹션. 실패는 null(다음 sync 때 재시도). */
    private function weaponDetail(string $base, string $slug): ?array
    {
        $html = $this->getHtml("{$base}/ko/weapons/{$slug}");
        if ($html === null) {
            return null;
        }

        $text = preg_replace('/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/u', '', $html);
        $text = preg_replace('/<[^>]+>/u', ' ', $text); // 태그를 공백으로(붙어버림 방지)
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text)) ?? '');

        $sections = [];

        // 스탯: "공격력 374.68 크리티컬 18.00%" 패턴(서브스탯 명칭은 가변)
        if (preg_match('/공격력\s+([\d.]+)\s+(\S+)\s+([\d.]+%)/u', $text, $m)) {
            $sections[] = ['title' => '스탯(만렙 기준)', 'rows' => [
                ['label' => '공격력', 'value' => $m[1]],
                ['label' => $m[2], 'value' => $m[3]],
            ]];
        }

        // 스킬: "스킬 {이름} {설명} 정보" 구간
        if (preg_match('/스킬\s+(\S[^ ]{0,40}?)\s+(.+?)\s+정보\s/u', $text, $m)) {
            $sections[] = ['title' => '스킬 · '.mb_substr($m[1], 0, 40), 'paragraphs' => [mb_substr(trim($m[2]), 0, 600)]];
        }

        // 설정(정보): "정보 {설정 텍스트} 문의하기" 구간
        if (preg_match('/\s정보\s+(.+?)\s+문의하기/u', $text, $m)) {
            $sections[] = ['title' => '정보', 'paragraphs' => [mb_substr(trim($m[1]), 0, 600)]];
        }

        return $sections !== [] ? $sections : null;
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) === 1 ? $m[1] : null;
    }
}
