<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubcultureGameInfo\AlternativePartyRequest;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\AlternativeParties\AlternativePartyService;
use App\Services\SubcultureGameInfo\Raids\RaidQueryService;
use App\Services\SubcultureGameInfo\Raids\SubstituteRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RaidController extends Controller
{
    public function __construct(
        private RaidQueryService $query,
        private AlternativePartyService $alternativeParties,
        private SubstituteRecommendationService $substituteRecommendations,
    ) {}

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

    /**
     * 미보유 캐릭터 제외 실전 편성 — 원본 랭킹 사이트(몰루로그/레츠도로) 프록시.
     * body: { exclude: [external_key...], page?, difficulty?(블아 총력전), armor?(블아 대결전: 장갑명) }
     */
    public function alternativeParties(AlternativePartyRequest $request, Raid $raid): JsonResponse
    {
        return response()->json([
            'data' => $this->alternativeParties->findParties(
                $raid,
                $request->excludeKeys(),
                $request->includeKeys(),
                $request->pageNumber(),
                $request->difficulty(),
                $request->armor(),
            ),
        ]);
    }

    /**
     * 학생별 출전 횟수(블아 전용) — 대체 캐릭터 후보의 실전 채용 빈도 표시용.
     */
    public function studentUsage(Raid $raid): JsonResponse
    {
        return response()->json([
            'data' => $this->alternativeParties->studentUsage($raid),
        ]);
    }

    /**
     * 미보유 캐릭터의 대체 후보를 Gemini 에게 추천받는다(내 풀 조합의 수동 대체 지정 보조).
     * body: { character_key, owned: [external_key...] } — 후보는 보유 목록으로 강제(닫힌 어휘)
     */
    public function substituteRecommendations(Request $request, Raid $raid): JsonResponse
    {
        $validated = $request->validate([
            'character_key' => ['required', 'string', 'max:100'],
            'owned' => ['required', 'array', 'min:1', 'max:500'],
            'owned.*' => ['string', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->substituteRecommendations->recommend(
                $raid,
                $validated['character_key'],
                $validated['owned'],
            ),
        ]);
    }
}
