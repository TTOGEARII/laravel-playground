<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\EventChallenge;
use App\Models\SubcultureGameInfo\Game;
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

        $stages = EventChallenge::query()
            ->where('subculture_game_id', $game->id)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString()))
            ->orderBy('stage_label')
            ->get();

        $first = $stages->first();

        return response()->json([
            'data' => [
                'event' => $first === null ? null : [
                    'name' => $first->event_name,
                    'starts_at' => $first->starts_at?->toDateString(),
                    'ends_at' => $first->ends_at?->toDateString(),
                    'source_url' => $first->source_url,
                ],
                'stages' => $stages->map(fn (EventChallenge $c) => [
                    'label' => $c->stage_label,
                    'name' => $c->stage_name,
                    'condition' => $c->clear_condition,
                    'summary' => $c->summary,
                    'video_url' => $c->video_url,
                    'extra_videos' => $c->extra_videos ?? [],
                    'mentioned' => $c->mentioned ?? [],
                ]),
            ],
        ]);
    }
}
