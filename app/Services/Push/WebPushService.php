<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * 웹푸시 발송 — PWA 알림(새 리딤코드 등록 등).
 * VAPID 키가 없으면 조용히 스킵(graceful), 만료·해지된 구독(404/410)은 발송 중 정리한다.
 */
class WebPushService
{
    public function enabled(): bool
    {
        return filled(config('services.webpush.public_key'))
            && filled(config('services.webpush.private_key'));
    }

    /**
     * 모든 구독자에게 알림을 보낸다.
     *
     * @param  string  $url  클릭 시 열 경로(예: /subculture-game-info)
     * @return array{sent: int, pruned: int, failed: int}
     */
    public function broadcast(string $title, string $body, string $url): array
    {
        $stats = ['sent' => 0, 'pruned' => 0, 'failed' => 0];

        if (! $this->enabled()) {
            Log::info('[PUSH] VAPID 키 미설정 — 발송 스킵');

            return $stats;
        }

        $subscriptions = PushSubscription::all();
        if ($subscriptions->isEmpty()) {
            return $stats;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('services.webpush.subject'),
                    'publicKey' => (string) config('services.webpush.public_key'),
                    'privateKey' => (string) config('services.webpush.private_key'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PUSH] WebPush 초기화 실패', ['error' => $e->getMessage()]);

            return $stats;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE);

        // 구독 건별로 발송·예외를 격리 — 손상된 키 한 건이 전체 발송을 막지 않도록
        foreach ($subscriptions as $row) {
            try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $row->endpoint,
                        'keys' => ['p256dh' => $row->p256dh_key, 'auth' => $row->auth_key],
                    ]),
                    $payload,
                );
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::info('[PUSH] 발송 예외', ['endpoint' => mb_substr($row->endpoint, 0, 80), 'error' => $e->getMessage()]);

                continue;
            }

            if ($report->isSuccess()) {
                $stats['sent']++;
            } elseif ($report->isSubscriptionExpired()) {
                // 만료/해지된 구독은 정리(404/410)
                $row->delete();
                $stats['pruned']++;
            } else {
                $stats['failed']++;
                Log::info('[PUSH] 발송 실패', ['endpoint' => mb_substr($row->endpoint, 0, 80), 'reason' => $report->getReason()]);
            }
        }

        Log::info('[PUSH] 발송 완료', $stats + ['title' => $title]);

        return $stats;
    }
}
