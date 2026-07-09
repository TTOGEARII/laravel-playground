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

        $webPush = $this->makeWebPush();
        if ($webPush === null) {
            return $stats;
        }

        $payload = $this->payload($title, $body, $url);

        foreach ($subscriptions as $row) {
            $stats[$this->send($webPush, $row, $payload)]++;
        }

        Log::info('[PUSH] 발송 완료', $stats + ['title' => $title]);

        return $stats;
    }

    /**
     * 단일 구독에만 발송(알림 테스트 등).
     *
     * @return string sent|pruned|failed
     */
    public function sendTo(PushSubscription $row, string $title, string $body, string $url): string
    {
        $webPush = $this->enabled() ? $this->makeWebPush() : null;
        if ($webPush === null) {
            return 'failed';
        }

        return $this->send($webPush, $row, $this->payload($title, $body, $url));
    }

    private function makeWebPush(): ?WebPush
    {
        try {
            return new WebPush([
                'VAPID' => [
                    'subject' => (string) config('services.webpush.subject'),
                    'publicKey' => (string) config('services.webpush.public_key'),
                    'privateKey' => (string) config('services.webpush.private_key'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PUSH] WebPush 초기화 실패', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function payload(string $title, string $body, string $url): string
    {
        return json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 한 구독에 발송하고 결과를 돌려준다. 예외를 밖으로 내지 않아
     * 손상된 키 한 건이 전체 발송을 막지 않는다. 만료 구독(404/410)은 정리.
     *
     * @return string sent|pruned|failed
     */
    private function send(WebPush $webPush, PushSubscription $row, string $payload): string
    {
        try {
            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $row->endpoint,
                    'keys' => ['p256dh' => $row->p256dh_key, 'auth' => $row->auth_key],
                ]),
                $payload,
            );
        } catch (\Throwable $e) {
            Log::info('[PUSH] 발송 예외', ['endpoint' => mb_substr($row->endpoint, 0, 80), 'error' => $e->getMessage()]);

            return 'failed';
        }

        if ($report->isSuccess()) {
            return 'sent';
        }
        if ($report->isSubscriptionExpired()) {
            $row->delete();

            return 'pruned';
        }
        Log::info('[PUSH] 발송 실패', ['endpoint' => mb_substr($row->endpoint, 0, 80), 'reason' => $report->getReason()]);

        return 'failed';
    }
}
