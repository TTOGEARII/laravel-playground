<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 게임 단위 최근 공략글 피드 — 레이드가 없는 게임(트릭컬/브더2)도
 * 대시보드에서 커뮤니티 공략을 바로 볼 수 있게 한다(guides 모듈).
 */
class GuidePostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', 'string', 'max:50'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);

        $game = Game::where('slug', $validated['game'])->firstOrFail();
        $limit = (int) ($validated['limit'] ?? 10);

        // 최근 레이드에 연결된 공략글 우선 → 추천수 많은 순 → 최신순.
        // (수집 자체가 keep_days(60일) 내 글만 유지하므로 별도 기간 필터는 불필요)
        $posts = GuidePost::query()
            ->where('subculture_game_id', $game->id)
            ->with('raid:id,name')
            ->orderByRaw('CASE WHEN subculture_raid_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByDesc('rate')
            ->orderByDesc('posted_at')
            ->limit($limit)
            ->get()
            ->map(fn (GuidePost $post) => [
                'title' => $post->title,
                'url' => $post->url,
                'source' => $post->source,
                'posted_at' => $post->posted_at?->toIso8601String(),
                'views' => $post->views,
                'rate' => $post->rate,
                'raid_id' => $post->subculture_raid_id,
                'raid_name' => $post->raid?->name,
            ]);

        return response()->json(['data' => $posts, 'meta' => ['total' => $posts->count()]]);
    }
}
