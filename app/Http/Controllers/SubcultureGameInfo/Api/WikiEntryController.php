<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\WikiEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 게임 위키 항목(카테고리별) — 위키 정보 탭(WikiDex) 데이터.
 * 목록은 detail 을 제외해 가볍게, 상세는 항목 단건 조회.
 */
class WikiEntryController extends Controller
{
    /** 쿼리: game(slug, 필수), menu(menu_key, 선택 — 없으면 첫 메뉴) */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['game' => ['required', 'string'], 'menu' => ['nullable', 'string', 'max:60']]);
        $game = Game::where('slug', $request->query('game'))->firstOrFail();

        // 메뉴(카테고리) 목록 — 탭 렌더용
        $menus = WikiEntry::forGame($game->id)
            ->selectRaw('menu_key, menu_label, count(*) as cnt')
            ->groupBy('menu_key', 'menu_label')
            ->orderByRaw('CAST(menu_key AS UNSIGNED), menu_key') // 위키 메뉴 id 순(에이전트/캐릭터 먼저)
            ->get()
            ->map(fn ($m) => ['key' => $m->menu_key, 'label' => $m->menu_label, 'count' => (int) $m->cnt])
            ->values();

        $menuKey = (string) ($request->query('menu') ?: ($menus[0]['key'] ?? ''));

        $entries = $menuKey === '' ? collect() : WikiEntry::forGame($game->id)
            ->where('menu_key', $menuKey)
            ->orderBy('name')
            ->get(['id', 'external_key', 'name', 'icon_url', 'filters']);

        return response()->json([
            'data' => $entries,
            'meta' => ['menus' => $menus, 'menu' => $menuKey],
        ]);
    }

    /** 항목 상세(정규화 섹션 포함). */
    public function show(WikiEntry $entry): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $entry->id,
            'name' => $entry->name,
            'menu_label' => $entry->menu_label,
            'icon_url' => $entry->icon_url,
            'filters' => $entry->filters ?? [],
            'detail' => $entry->detail ?? [],
        ]]);
    }
}
