<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 정보검색 대시보드 데이터 — 모집중 학생(배너)·진행중 컨텐츠(이벤트)·미래시.
 * 모두 게임 슬러그 기준 조회(공개).
 */
class InfoController extends Controller
{
    public function __construct(private ScheduleService $schedule) {}

    /** 모집중 학생(픽업 배너) — 현재 scope. */
    public function banners(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->schedule->banners($this->game($request)->id)]);
    }

    /** 진행중 컨텐츠(이벤트) — 현재 scope. kind 기본 event(레이드는 별도 모듈). */
    public function events(Request $request): JsonResponse
    {
        $kind = $request->query('kind', 'event');

        return response()->json(['data' => $this->schedule->events(
            $this->game($request)->id,
            'current',
            $kind === 'all' ? null : (string) $kind,
        )]);
    }

    /** 미래시 — forecast 배너+이벤트 통합 타임라인. */
    public function schedule(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->schedule->futureTimeline($this->game($request)->id)]);
    }

    private function game(Request $request): Game
    {
        $request->validate(['game' => ['required', 'string']]);

        return Game::where('slug', $request->query('game'))->firstOrFail();
    }
}
