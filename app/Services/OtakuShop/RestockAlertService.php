<?php

namespace App\Services\OtakuShop;

use App\Models\OtakuShop\OtakuWish;
use App\Models\PushSubscription;
use App\Services\Push\WebPushService;
use Illuminate\Support\Facades\Log;

/**
 * 찜 상품 재입고 웹푸시 — 크롤에서 품절→구매가능으로 바뀐 상품을 찜한 유저에게 알린다.
 * 유저별로 묶어 한 건으로 발송(같은 크롤에 여러 찜 상품이 입고돼도 알림 폭탄 방지).
 */
class RestockAlertService
{
    public function __construct(private WebPushService $push) {}

    /**
     * @param  int[]  $productIds  이번 크롤에서 재입고된 상품 ID
     * @return array{products: int, users: int, sent: int, pruned: int, failed: int}
     */
    public function notify(array $productIds): array
    {
        $stats = ['products' => 0, 'users' => 0, 'sent' => 0, 'pruned' => 0, 'failed' => 0];

        if ($productIds === [] || ! $this->push->enabled()) {
            return $stats;
        }

        $wishes = OtakuWish::whereIn('ok_wish_product_id', $productIds)
            ->with('product:ok_product_id,ok_product_title')
            ->get();
        if ($wishes->isEmpty()) {
            return $stats;
        }

        $stats['products'] = $wishes->pluck('ok_wish_product_id')->unique()->count();

        foreach ($wishes->groupBy('user_id') as $userId => $userWishes) {
            $subscriptions = PushSubscription::where('user_id', $userId)->get();
            if ($subscriptions->isEmpty()) {
                continue; // 찜은 했지만 푸시 구독이 없는 유저
            }

            $titles = $userWishes->map(fn (OtakuWish $w) => $w->product?->ok_product_title)->filter()->values();
            $first = mb_strimwidth($titles->first() ?? '찜한 상품', 0, 60, '…');
            $body = $titles->count() === 1 ? $first : "{$first} 외 ".($titles->count() - 1).'개';

            $result = $this->push->sendToSubscriptions(
                $subscriptions,
                '찜한 상품 재고 입고 🛒',
                $body.' — 다시 구매할 수 있어요',
                '/otaku-shop',
            );

            $stats['users']++;
            $stats['sent'] += $result['sent'];
            $stats['pruned'] += $result['pruned'];
            $stats['failed'] += $result['failed'];
        }

        Log::info('[PUSH] 재입고 알림 발송', $stats);

        return $stats;
    }
}
