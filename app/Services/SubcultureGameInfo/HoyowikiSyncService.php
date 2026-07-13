<?php

namespace App\Services\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\WikiEntry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 호요랩 공식 위키(HoYoWiki) 동기화 — 젠존제/스타레일.
 * 홈이 아니라 위키의 카테고리(메뉴) 전체를 순회하며, 항목 목록 + 항목별 상세 페이지까지 수집한다.
 *   목록: POST /hoyowiki/{app}/wapi/get_entry_page_list (menu_id, 페이지네이션)
 *   상세: GET  /hoyowiki/{app}/wapi/entry_page?entry_page_id= (모듈 구조 JSON, ko-kr)
 * 상세는 신규/미수집 항목만 가져온다(위키 특성상 재변경이 드물어 요청량 절약).
 * 방어: 메뉴/목록 0건이면 해당 파트 스킵.
 */
class HoyowikiSyncService
{
    private const SOURCE = 'hoyowiki';

    /** @return array{menus:int, entries:int, details:int} */
    public function sync(Game $game): array
    {
        $cfg = (array) config('subculture-game-info.raids.hoyowiki');
        $appCfg = $cfg['apps'][$game->slug] ?? null;
        if ($appCfg === null) {
            Log::warning('[Hoyowiki] 미지원 게임', ['game' => $game->slug]);

            return ['menus' => 0, 'entries' => 0, 'details' => 0];
        }

        $app = (string) $appCfg['app'];
        $exclude = array_map('strval', (array) ($appCfg['exclude_menus'] ?? []));
        $menus = $this->fetchMenus($cfg, $app);
        $stats = ['menus' => 0, 'entries' => 0, 'details' => 0];

        foreach ($menus as $menu) {
            $menuId = (string) $menu['menu_id'];
            if ($menuId === '0' || in_array($menuId, $exclude, true)) {
                continue;
            }
            $stats['menus']++;

            $seen = [];
            foreach ($this->fetchEntries($cfg, $app, $menuId) as $item) {
                $entryId = (string) ($item['entry_page_id'] ?? '');
                if ($entryId === '') {
                    continue;
                }

                $entry = WikiEntry::firstOrNew([
                    'subculture_game_id' => $game->id,
                    'source' => self::SOURCE,
                    'menu_key' => $menuId,
                    'external_key' => $entryId,
                ]);
                $entry->menu_label = (string) $menu['name'];
                $entry->name = (string) ($item['name'] ?? $entryId);
                $entry->icon_url = $item['icon_url'] ?? null;
                $entry->filters = $this->filtersFrom($item);

                // 상세는 신규/미수집 항목만(수백 건 재수집 방지) — 항목당 1요청 + 딜레이
                if ($entry->detail === null) {
                    $detail = $this->fetchDetail($cfg, $app, $entryId);
                    if ($detail !== null) {
                        $entry->detail = $detail;
                        $stats['details']++;
                    }
                    usleep(((int) ($cfg['fetch_delay_ms'] ?? 250)) * 1000);
                }

                $entry->save();
                $seen[] = $entryId;
                $stats['entries']++;
            }

            // 위키에서 내려간 항목 정리(메뉴 단위 멱등) — 목록이 정상 수집된 경우에만
            if ($seen !== []) {
                WikiEntry::forGame($game->id)->where('source', self::SOURCE)
                    ->where('menu_key', $menuId)->whereNotIn('external_key', $seen)->delete();
            }
        }

        return $stats;
    }

    /* ---------------------------------- HTTP ---------------------------------- */

    private function headers(string $app): array
    {
        return [
            'User-Agent' => (string) config('subculture-game-info.http.user_agent'),
            'Referer' => 'https://wiki.hoyolab.com/',
            'X-Rpc-Language' => (string) config('subculture-game-info.raids.hoyowiki.lang', 'ko-kr'),
            'X-Rpc-Wiki_app' => $app,
        ];
    }

    /** @return array<int, array{menu_id: string, name: string}> */
    private function fetchMenus(array $cfg, string $app): array
    {
        try {
            $res = Http::withHeaders($this->headers($app))
                ->timeout((int) $cfg['timeout'])
                ->get(rtrim((string) $cfg['static_base'], '/')."/hoyowiki/{$app}/wapi/home/navigation", ['lang' => $cfg['lang']]);

            $navs = (array) data_get($res->json(), 'data.nav', []);

            return collect($navs)
                ->map(fn ($n) => ['menu_id' => (string) data_get($n, 'menu.menu_id', '0'), 'name' => (string) ($n['name'] ?? '')])
                ->filter(fn ($m) => $m['menu_id'] !== '0' && $m['name'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[Hoyowiki] 메뉴 수집 실패', ['app' => $app, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /** 메뉴의 전체 항목(페이지네이션 순회). @return \Generator<array> */
    private function fetchEntries(array $cfg, string $app, string $menuId): \Generator
    {
        $base = rtrim((string) $cfg['base'], '/');
        for ($page = 1; $page <= 40; $page++) { // 상한(메뉴당 2000건) — 무한 루프 방지
            try {
                $res = Http::withHeaders($this->headers($app))
                    ->timeout((int) $cfg['timeout'])
                    ->post("{$base}/hoyowiki/{$app}/wapi/get_entry_page_list", [
                        'filters' => [],
                        'menu_id' => $menuId,
                        'page_num' => $page,
                        'page_size' => 50,
                        'use_es' => true,
                    ]);

                $list = (array) data_get($res->json(), 'data.list', []);
            } catch (\Throwable $e) {
                Log::warning('[Hoyowiki] 목록 수집 실패', ['app' => $app, 'menu' => $menuId, 'page' => $page, 'error' => $e->getMessage()]);

                return;
            }

            if ($list === []) {
                return;
            }
            yield from $list;
            if (count($list) < 50) {
                return;
            }
        }
    }

    /** 항목 상세를 정규화 섹션 목록으로. 실패는 null. */
    private function fetchDetail(array $cfg, string $app, string $entryId): ?array
    {
        try {
            $res = Http::withHeaders($this->headers($app))
                ->timeout((int) $cfg['timeout'])
                ->get(rtrim((string) $cfg['base'], '/')."/hoyowiki/{$app}/wapi/entry_page", [
                    'entry_page_id' => $entryId,
                    'lang' => $cfg['lang'],
                ]);

            $page = data_get($res->json(), 'data.page');
            if (! is_array($page)) {
                return null;
            }

            return $this->normalize($page);
        } catch (\Throwable $e) {
            Log::warning('[Hoyowiki] 상세 수집 실패', ['app' => $app, 'entry' => $entryId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /* ---------------------------------- 정규화 ---------------------------------- */

    /** 목록 filter_values → 배지 값 목록(최대 6). */
    private function filtersFrom(array $item): array
    {
        return collect((array) ($item['filter_values'] ?? []))
            ->flatMap(fn ($f) => (array) data_get($f, 'values', []))
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->unique()->take(6)
            ->map(fn (string $v) => ['value' => $v])
            ->values()->all();
    }

    /**
     * entry_page 모듈 구조를 읽기 좋은 섹션으로 정규화.
     * 지원 패턴: {list:[{key,value[]}]}(속성표) · {list:[{title,desc,children[]}]}(스킬/스토리/시네마).
     * 갤러리·음성·영상 등 미디어 모듈은 생략한다.
     */
    private function normalize(array $page): array
    {
        $sections = [];

        $intro = $this->cleanHtml((string) ($page['desc'] ?? ''));
        if ($intro !== '') {
            $sections[] = ['title' => '소개', 'paragraphs' => [mb_substr($intro, 0, 600)]];
        }

        foreach ((array) ($page['modules'] ?? []) as $module) {
            $title = trim((string) ($module['name'] ?? ''));
            foreach ((array) ($module['components'] ?? []) as $component) {
                $data = json_decode((string) ($component['data'] ?? ''), true);
                $list = is_array($data) ? ($data['list'] ?? null) : null;
                if (! is_array($list) || $list === []) {
                    continue;
                }

                $rows = [];
                $paragraphs = [];
                foreach ($list as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (isset($item['key'])) {
                        $value = $this->flattenValue($item['value'] ?? null);
                        if (trim((string) $item['key']) !== '' && $value !== '') {
                            $rows[] = ['label' => mb_substr(trim((string) $item['key']), 0, 40), 'value' => mb_substr($value, 0, 300)];
                        }
                    } elseif (isset($item['title'])) {
                        foreach ($this->talentParagraphs($item) as $p) {
                            $paragraphs[] = $p;
                        }
                    }
                }

                if ($rows !== [] || $paragraphs !== []) {
                    $sections[] = array_filter([
                        'title' => $title !== '' ? $title : null,
                        'rows' => array_slice($rows, 0, 30) ?: null,
                        'paragraphs' => array_slice($paragraphs, 0, 30) ?: null,
                    ]);
                }
                if (count($sections) >= 12) {
                    return $sections;
                }
            }
        }

        return $sections;
    }

    /** 스킬/스토리류 항목(title/desc/children) → 문단 목록. */
    private function talentParagraphs(array $item): array
    {
        $out = [];
        $title = trim((string) ($item['title'] ?? ''));
        $desc = $this->cleanHtml((string) ($item['desc'] ?? ''));
        if ($title !== '' || $desc !== '') {
            $line = trim($title.($desc !== '' ? ' — '.$desc : ''));
            if ($line !== '' && $line !== '—') {
                $out[] = mb_substr($line, 0, 600);
            }
        }
        foreach ((array) ($item['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $ct = trim((string) ($child['title'] ?? ''));
            $cd = $this->cleanHtml((string) ($child['desc'] ?? ''));
            if ($ct === '' && $cd === '') {
                continue;
            }
            $out[] = mb_substr(trim(($ct !== '' ? $ct.' — ' : '').$cd), 0, 600);
        }

        return $out;
    }

    /** baseInfo value 배열(HTML/소재참조 토큰 혼합) → 평문. */
    private function flattenValue(mixed $values): string
    {
        if (! is_array($values)) {
            return '';
        }
        $parts = [];
        foreach ($values as $v) {
            if (! is_string($v)) {
                continue;
            }
            // $[{...}]$ 소재/링크 참조 → name 만 추출
            $v = preg_replace_callback('/\$\[(.+?)\]\$/s', function ($m) {
                $refs = json_decode('['.$m[1].']', true);

                return collect(is_array($refs) ? $refs : [])->pluck('name')->filter()->implode(', ');
            }, $v);
            $parts[] = $this->cleanHtml($v);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function cleanHtml(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))) ?? '');
    }
}
