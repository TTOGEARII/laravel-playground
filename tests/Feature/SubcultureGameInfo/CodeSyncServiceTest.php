<?php

namespace Tests\Feature\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 동기화 권위 규칙 테스트.
 * - 동일성 키: (game, region, code)
 * - 메인(aggregator) > 커뮤니티(community)
 * - 확정 상태(active/expired)는 미검증으로 덮지 않음
 */
class CodeSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private CodeSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = new CodeSyncService;
        Game::create([
            'slug' => 'genshin',
            'name' => '원신',
            'region_default' => 'asia',
            'sort' => 1,
            'active_flg' => true,
        ]);
    }

    private function dto(array $overrides = []): CollectedCodeDto
    {
        return new CollectedCodeDto(
            gameSlug: $overrides['gameSlug'] ?? 'genshin',
            code: $overrides['code'] ?? 'GENSHINGIFT',
            sourceType: $overrides['sourceType'] ?? SourceType::Aggregator,
            source: $overrides['source'] ?? 'test',
            region: $overrides['region'] ?? CodeRegion::Global,
            rewards: $overrides['rewards'] ?? null,
            status: $overrides['status'] ?? CodeStatus::Unverified,
            sourceUrl: $overrides['sourceUrl'] ?? null,
        );
    }

    public function test_community_unverified_then_aggregator_active_is_promoted(): void
    {
        // 커뮤니티 미검증 먼저
        $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Community,
            'source' => 'dc',
            'status' => CodeStatus::Unverified,
        ])]);

        // 이후 aggregator active 가 오면 승격
        $stats = $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Aggregator,
            'source' => 'ennead',
            'status' => CodeStatus::Active,
        ])]);

        $this->assertSame(1, $stats['updated']);

        $code = RedeemCode::first();
        $this->assertSame(CodeStatus::Active, $code->status);
        $this->assertSame(SourceType::Aggregator, $code->source_type);
        $this->assertSame('ennead', $code->source);
        $this->assertNotNull($code->verified_at);
    }

    public function test_aggregator_active_is_not_overwritten_by_community_unverified(): void
    {
        // aggregator active 먼저
        $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Aggregator,
            'source' => 'ennead',
            'status' => CodeStatus::Active,
        ])]);

        // 이후 community unverified 가 와도 status/source_type 안 덮임 → 스킵
        $stats = $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Community,
            'source' => 'dc',
            'status' => CodeStatus::Unverified,
        ])]);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['updated']);

        $code = RedeemCode::first();
        $this->assertSame(CodeStatus::Active, $code->status);
        $this->assertSame(SourceType::Aggregator, $code->source_type);
        $this->assertSame('ennead', $code->source);
    }

    public function test_dedup_same_key_creates_once(): void
    {
        $stats = $this->sync->sync([
            $this->dto(['status' => CodeStatus::Unverified]),
            $this->dto(['status' => CodeStatus::Unverified]), // 동일 키 중복
        ]);

        $this->assertSame(1, $stats['created']);
        // 두 번째는 변경 없으므로 skip
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, RedeemCode::count());
    }

    public function test_rewards_filled_only_when_empty(): void
    {
        $this->sync->sync([$this->dto(['rewards' => '원석 x60'])]);

        // 이미 채워진 rewards 는 덮어쓰지 않음
        $stats = $this->sync->sync([$this->dto(['rewards' => '다른 보상'])]);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame('원석 x60', RedeemCode::first()->rewards);
    }

    public function test_different_region_creates_separate_record(): void
    {
        $stats = $this->sync->sync([
            $this->dto(['region' => CodeRegion::Global]),
            $this->dto(['region' => CodeRegion::Asia]),
        ]);

        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, RedeemCode::count());
    }

    public function test_skips_unknown_game_slug(): void
    {
        $stats = $this->sync->sync([$this->dto(['gameSlug' => 'doesnotexist'])]);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, RedeemCode::count());
    }
}
