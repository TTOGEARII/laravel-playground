<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\CodeRedemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 로그인 사용자의 리딤코드 "교환 완료" 체크 기록 API (세션 인증).
 * 비로그인 사용자는 클라이언트 localStorage 로 처리하므로 이 엔드포인트를 쓰지 않는다.
 */
class RedemptionController extends Controller
{
    /** 현재 사용자가 교환 완료로 표시한 코드 ID 목록. */
    public function index(): JsonResponse
    {
        $ids = CodeRedemption::where('user_id', Auth::id())
            ->pluck('redeem_code_id')
            ->all();

        return response()->json(['data' => $ids]);
    }

    /** 교환 완료 표시(멱등). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'redeem_code_id' => ['required', 'integer', 'exists:redeem_codes,id'],
        ]);

        CodeRedemption::firstOrCreate(
            ['user_id' => Auth::id(), 'redeem_code_id' => $validated['redeem_code_id']],
            ['redeemed_at' => now()],
        );

        return response()->json(['data' => ['redeem_code_id' => $validated['redeem_code_id'], 'redeemed' => true]]);
    }

    /** 교환 완료 표시 해제(멱등). */
    public function destroy(int $code): JsonResponse
    {
        CodeRedemption::where('user_id', Auth::id())
            ->where('redeem_code_id', $code)
            ->delete();

        return response()->json(['data' => ['redeem_code_id' => $code, 'redeemed' => false]]);
    }
}
