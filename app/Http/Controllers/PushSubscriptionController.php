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
    /** 구독 등록(멱등 — 같은 endpoint 재구독 시 키 갱신). topics 지정 시 해당 주제만 수신(미지정=전체). */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'topics' => ['sometimes', 'array'],
            'topics.*' => ['string', 'in:'.implode(',', PushSubscription::TOPICS)],
        ]);

        $requested = isset($validated['topics']) ? array_values(array_unique($validated['topics'])) : null;
        $existing = PushSubscription::where('endpoint_hash', hash('sha256', $validated['endpoint']))->first();
        // 기존 구독의 주제는 좁히지 않는다 — null(전체)이면 유지, 배열이면 요청 주제를 합집합으로 추가
        $topics = ($existing === null || $existing->topics === null || $requested === null)
            ? $requested
            : array_values(array_unique([...$existing->topics, ...$requested]));
        if ($existing !== null && $existing->topics === null) {
            $topics = null; // 전체 수신 구독은 전체 유지
        }

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'endpoint' => $validated['endpoint'],
                'p256dh_key' => $validated['keys']['p256dh'],
                'auth_key' => $validated['keys']['auth'],
                'user_id' => $request->user()?->id,
                'topics' => $topics,
            ],
        );

        return response()->json(['data' => ['subscribed' => true, 'topics' => $subscription->topics]]);
    }

    /** 구독 상태 조회 — 알림 설정 UI 초기화용(구독 여부 + 선택한 주제). */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
        ]);

        $subscription = PushSubscription::where('endpoint_hash', hash('sha256', $validated['endpoint']))->first();

        return response()->json(['data' => [
            'subscribed' => $subscription !== null,
            'topics' => $subscription?->topics, // null = 전체 수신
        ]]);
    }

    /** 알림 주제 갱신 — 이 브라우저 구독의 수신 주제만 바꾼다(구독 자체는 유지). */
    public function updateTopics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'topics' => ['required', 'array'],
            'topics.*' => ['string', 'in:'.implode(',', PushSubscription::TOPICS)],
        ]);

        $subscription = PushSubscription::where('endpoint_hash', hash('sha256', $validated['endpoint']))->first();
        if ($subscription === null) {
            return response()->json(['message' => '구독을 찾을 수 없습니다'], 404);
        }

        $subscription->update(['topics' => array_values(array_unique($validated['topics']))]);

        return response()->json(['data' => ['topics' => $subscription->topics]]);
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
