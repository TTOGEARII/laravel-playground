<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\RaidQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RaidController extends Controller
{
    public function __construct(private RaidQueryService $query) {}

    /**
     * 레이드 목록. 쿼리: game(slug), status(active|upcoming|ended)
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        if ($status !== null && ! in_array($status, ['active', 'upcoming', 'ended'], true)) {
            $status = null;
        }

        $data = $this->query->listRaids($request->query('game'), $status);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $data->count()],
        ]);
    }

    /** 레이드 상세(보스 정보 + 추천 편성 + 공략글). */
    public function show(Raid $raid): JsonResponse
    {
        return response()->json(['data' => $this->query->showRaid($raid)]);
    }
}
