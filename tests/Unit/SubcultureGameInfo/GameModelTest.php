<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use Tests\TestCase;

/**
 * Game::redeemUrlFor 단위 테스트. (DB 불필요 — 모델 인스턴스만 사용)
 */
class GameModelTest extends TestCase
{
    public function test_redeem_url_replaces_code_placeholder(): void
    {
        $game = new Game(['redeem_url_template' => 'https://example.com/gift?code={code}']);

        $this->assertSame(
            'https://example.com/gift?code=ABC123',
            $game->redeemUrlFor('ABC123')
        );
    }

    public function test_redeem_url_encodes_code(): void
    {
        $game = new Game(['redeem_url_template' => 'https://example.com/gift?code={code}']);

        $this->assertSame(
            'https://example.com/gift?code=A%20B%2FC',
            $game->redeemUrlFor('A B/C')
        );
    }

    public function test_redeem_url_returns_template_as_is_without_placeholder(): void
    {
        $game = new Game(['redeem_url_template' => 'https://coupon.example.com/']);

        $this->assertSame('https://coupon.example.com/', $game->redeemUrlFor('ABC123'));
    }

    public function test_redeem_url_returns_null_when_template_null(): void
    {
        $game = new Game(['redeem_url_template' => null]);

        $this->assertNull($game->redeemUrlFor('ABC123'));
    }
}
