<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 웹푸시 주제(topics) — 구독별 알림 선택(redeem/concert/event). null = 전체 수신(하위호환).
 */
class PushTopicsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example/sub-1';

    private function subscribe(array $extra = []): void
    {
        $this->postJson('/push/subscribe', array_merge([
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => 'pk', 'auth' => 'ak'],
        ], $extra))->assertOk();
    }

    public function test_subscribe_without_topics_receives_all(): void
    {
        $this->subscribe();

        $sub = PushSubscription::first();
        $this->assertNull($sub->topics);
        $this->assertTrue($sub->wantsTopic('redeem'));
        $this->assertTrue($sub->wantsTopic('concert'));
    }

    public function test_scoped_subscribe_and_union_merge_on_resubscribe(): void
    {
        $this->subscribe(['topics' => ['concert']]);
        $this->assertSame(['concert'], PushSubscription::first()->topics);

        // 다른 주제로 재구독(리딤 페이지) → 좁히지 않고 합집합
        $this->subscribe(['topics' => ['redeem']]);
        $this->assertEqualsCanonicalizing(['concert', 'redeem'], PushSubscription::first()->topics);

        // 전체 수신 구독(topics null)은 스코프 재구독에도 전체 유지
        PushSubscription::first()->update(['topics' => null]);
        $this->subscribe(['topics' => ['concert']]);
        $this->assertNull(PushSubscription::first()->topics);
    }

    public function test_status_and_topic_update(): void
    {
        $this->postJson('/push/status', ['endpoint' => self::ENDPOINT])
            ->assertOk()->assertJsonPath('data.subscribed', false);

        $this->subscribe(['topics' => ['concert', 'event']]);
        $this->postJson('/push/status', ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJsonPath('data.subscribed', true)
            ->assertJsonPath('data.topics', ['concert', 'event']);

        // 주제 갱신(concert 끄기)
        $this->postJson('/push/topics', ['endpoint' => self::ENDPOINT, 'topics' => ['event']])
            ->assertOk()->assertJsonPath('data.topics', ['event']);
        $this->assertSame(['event'], PushSubscription::first()->topics);

        // 미구독 endpoint 는 404, 잘못된 주제는 422
        $this->postJson('/push/topics', ['endpoint' => 'https://push.example/none', 'topics' => ['event']])->assertNotFound();
        $this->postJson('/push/topics', ['endpoint' => self::ENDPOINT, 'topics' => ['bogus']])->assertUnprocessable();
    }

    public function test_for_topic_scope_filters_recipients(): void
    {
        PushSubscription::create(['endpoint' => 'e1', 'p256dh_key' => 'k', 'auth_key' => 'a', 'endpoint_hash' => 'h1', 'topics' => null]);
        PushSubscription::create(['endpoint' => 'e2', 'p256dh_key' => 'k', 'auth_key' => 'a', 'endpoint_hash' => 'h2', 'topics' => ['concert']]);
        PushSubscription::create(['endpoint' => 'e3', 'p256dh_key' => 'k', 'auth_key' => 'a', 'endpoint_hash' => 'h3', 'topics' => ['redeem']]);
        PushSubscription::create(['endpoint' => 'e4', 'p256dh_key' => 'k', 'auth_key' => 'a', 'endpoint_hash' => 'h4', 'topics' => []]);

        $concert = PushSubscription::forTopic('concert')->pluck('endpoint')->all();
        $this->assertEqualsCanonicalizing(['e1', 'e2'], $concert, 'null(전체)+concert 만 — redeem 전용·빈 배열 제외');

        $redeem = PushSubscription::forTopic('redeem')->pluck('endpoint')->all();
        $this->assertEqualsCanonicalizing(['e1', 'e3'], $redeem);
    }
}
