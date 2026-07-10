<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\EventChallenge;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\EventChallengePartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 진행 중 이벤트 챌린지 공략 — 대시보드 event-challenges 모듈용.
 * 최신 이벤트 하나의 스테이지들을 돌려주고, 종료된 이벤트는 노출하지 않는다.
 */
class EventChallengeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', 'string', 'max:50'],
        ]);

        $game = Game::where('slug', $validated['game'])->firstOrFail();

        // 동시 진행 이벤트(메인 + 미니)가 있을 수 있어 이벤트 단위로 묶어 돌려준다
        $events = EventChallenge::query()
            ->where('subculture_game_id', $game->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString()))
            ->orderBy('stage_label')
            ->get()
            ->groupBy('event_key')
            ->map(fn ($stages, string $key) => [
                'key' => $key,
                'name' => $stages->first()->event_name,
                'starts_at' => $stages->first()->starts_at?->toDateString(),
                'ends_at' => $stages->first()->ends_at?->toDateString(),
                'source_url' => $stages->first()->source_url,
                'stages' => $stages->map(fn (EventChallenge $c) => [
                    'id' => $c->id,
                    'label' => $c->stage_label,
                    'name' => $c->stage_name,
                    'condition' => $c->clear_condition,
                    'summary' => $c->summary,
                    'video_url' => $c->video_url,
                    'extra_videos' => $c->extra_videos ?? [],
                    'best_party' => $c->best_party ?? [],
                    'mentioned' => $c->mentioned ?? [],
                ])->values(),
            ])
            ->sortByDesc(fn (array $e) => $e['starts_at'] ?? '')
            ->values();

        return response()->json(['data' => ['events' => $events]]);
    }

    /** 내 풀 조합 — 추천 조합의 미보유를 보유 목록에서 Gemini 대체(1일 캐시). */
    public function myParty(Request $request, EventChallenge $challenge, EventChallengePartyService $service): JsonResponse
    {
        $validated = $request->validate([
            'owned' => ['array', 'max:400'],
            'owned.*' => ['string', 'max:100'],
        ]);

        return response()->json([
            'data' => ['party' => $service->myParty($challenge, $validated['owned'] ?? [])],
        ]);
    }
}
