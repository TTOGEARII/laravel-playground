<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Push\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 웹푸시 구독 등록/해지 — 브라우저 단위라 로그인 없이도 구독 가능(user_id 는 있으면 기록).
 */
class PushSubscriptionController extends Controller
{
    /** 구독 등록(멱등 — 같은 endpoint 재구독 시 키 갱신). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh_key' => $validated['keys']['p256dh'],
                'auth_key' => $validated['keys']['auth'],
                'user_id' => $request->user()?->id,
            ],
        );

        return response()->json(['data' => ['subscribed' => true]]);
    }

    /** 구독 해지. */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
        ]);

        PushSubscription::where('endpoint_hash', hash('sha256', $validated['endpoint']))->delete();

        return response()->json(['data' => ['subscribed' => false]]);
    }

    /** 테스트 알림 — 요청한 브라우저 자신의 구독에만 보낸다(전체 오발송 방지). */
    public function test(Request $request, WebPushService $push): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
        ]);

        $subscription = PushSubscription::where('endpoint_hash', hash('sha256', $validated['endpoint']))->first();
        if ($subscription === null) {
            return response()->json(['data' => ['result' => 'not_subscribed']], 404);
        }

        $result = $push->sendTo(
            $subscription,
            '푸시 알림 테스트 🔔',
            '이 알림이 보이면 정상 동작하는 거예요!',
            '/',
        );

        return response()->json(['data' => ['result' => $result]]);
    }
}
