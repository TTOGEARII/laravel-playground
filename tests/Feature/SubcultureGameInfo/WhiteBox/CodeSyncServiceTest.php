<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CodeSyncService 화이트박스: 동일성/교차검증/권위/usableOnly/빈값채움/markExpiredPastDue.
 */
class CodeSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private CodeSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = new CodeSyncService;
    }

    private function game(string $slug = 'genshin'): Game
    {
        return Game::create([
            'slug' => $slug,
            'name' => '테스트게임',
            'region_default' => 'asia',
            'sort' => 1,
            'active_flg' => true,
        ]);
    }

    private function dto(array $o = []): CollectedCodeDto
    {
        return new CollectedCodeDto(
            gameSlug: $o['gameSlug'] ?? 'genshin',
            code: $o['code'] ?? 'TESTCODE12',
            sourceType: $o['sourceType'] ?? SourceType::Aggregator,
            source: $o['source'] ?? 'ennead',
            region: $o['region'] ?? CodeRegion::Asia,
            rewards: $o['rewards'] ?? null,
            status: $o['status'] ?? CodeStatus::Unverified,
            sourceUrl: $o['sourceUrl'] ?? 'https://src.test',
            expiresAt: $o['expiresAt'] ?? null,
        );
    }

    // ---------------------------------------------------------------- ensureGames
    public function test_ensure_games_upserts_from_config(): void
    {
        $this->sync->ensureGames();
        $this->assertDatabaseHas('subculture_games', ['slug' => 'genshin', 'name' => '원신']);
        // 재실행해도 중복 생성 없음(updateOrCreate)
        $count = Game::count();
        $this->sync->ensureGames();
        $this->assertSame($count, Game::count());
    }

    // ---------------------------------------------------------------- 신규 생성
    public function test_new_code_sets_seen_sources_and_corroboration(): void
    {
        $this->game();
        $stats = $this->sync->sync([$this->dto(['status' => CodeStatus::Active, 'source' => 'ennead'])]);

        $this->assertSame(1, $stats['created']);
        $code = RedeemCode::first();
        $this->assertSame(['ennead'], $code->seen_sources);
        $this->assertSame(1, $code->corroboration_count);
        $this->assertSame(CodeStatus::Active, $code->status);
        $this->assertNotNull($code->verified_at);
    }

    // ---------------------------------------------------------------- 동일성 + 출처 병합
    public function test_resync_same_code_different_source_merges_and_increments(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['source' => 'ennead', 'status' => CodeStatus::Active])]);
        $stats = $this->sync->sync([$this->dto(['source' => 'seria', 'status' => CodeStatus::Active])]);

        $this->assertSame(1, $stats['updated']);
        $code = RedeemCode::first();
        $this->assertSame(['ennead', 'seria'], $code->seen_sources);
        $this->assertSame(2, $code->corroboration_count);
        // 같은 코드 1건만 존재 (동일성: 게임/리전/코드)
        $this->assertSame(1, RedeemCode::count());
    }

    public function test_resync_same_source_does_not_double_count(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['source' => 'ennead', 'status' => CodeStatus::Active])]);
        $this->sync->sync([$this->dto(['source' => 'ennead', 'status' => CodeStatus::Active])]);

        $code = RedeemCode::first();
        $this->assertSame(['ennead'], $code->seen_sources);
        $this->assertSame(1, $code->corroboration_count);
    }

    public function test_same_code_different_region_is_separate_row(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['region' => CodeRegion::Asia, 'status' => CodeStatus::Active])]);
        $this->sync->sync([$this->dto(['region' => CodeRegion::Global, 'status' => CodeStatus::Active])]);

        $this->assertSame(2, RedeemCode::count());
    }

    // ---------------------------------------------------------------- 권위 규칙
    public function test_community_promoted_to_aggregator(): void
    {
        $this->game();
        // 먼저 커뮤니티 미검증으로 생성
        $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Community, 'source' => 'dc', 'status' => CodeStatus::Unverified,
        ])]);
        // 이후 aggregator 가 같은 코드를 보고 → 승격
        $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Aggregator, 'source' => 'ennead', 'status' => CodeStatus::Active,
            'sourceUrl' => 'https://ennead.test',
        ])]);

        $code = RedeemCode::first();
        $this->assertSame(SourceType::Aggregator, $code->source_type);
        $this->assertSame('ennead', $code->source);
        $this->assertSame('https://ennead.test', $code->source_url);
    }

    public function test_aggregator_not_downgraded_by_community(): void
    {
        $this->game();
        $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Aggregator, 'source' => 'ennead', 'status' => CodeStatus::Active,
        ])]);
        $this->sync->sync([$this->dto([
            'sourceType' => SourceType::Community, 'source' => 'dc', 'status' => CodeStatus::Unverified,
        ])]);

        $code = RedeemCode::first();
        $this->assertSame(SourceType::Aggregator, $code->source_type);
        $this->assertSame('ennead', $code->source);
    }

    public function test_unverified_does_not_overwrite_confirmed_status(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['status' => CodeStatus::Active, 'source' => 'ennead'])]);
        // 미검증이 들어와도 active 유지
        $this->sync->sync([$this->dto(['status' => CodeStatus::Unverified, 'source' => 'dc', 'sourceType' => SourceType::Community])]);

        $this->assertSame(CodeStatus::Active, RedeemCode::first()->status);
    }

    public function test_confirmed_status_can_change_active_to_expired(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['status' => CodeStatus::Active, 'source' => 'ennead'])]);
        // seria 가 NOT_OK(=expired) 확정으로 보고 → 갱신
        $this->sync->sync([$this->dto(['status' => CodeStatus::Expired, 'source' => 'seria'])]);

        $this->assertSame(CodeStatus::Expired, RedeemCode::first()->status);
    }

    // ---------------------------------------------------------------- usableOnly
    public function test_usable_only_skips_new_expired_by_status(): void
    {
        $this->game();
        $stats = $this->sync->sync([$this->dto(['status' => CodeStatus::Expired])], usableOnly: true);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, RedeemCode::count());
    }

    public function test_usable_only_skips_new_with_past_expiry_date(): void
    {
        $this->game();
        $stats = $this->sync->sync([
            $this->dto(['status' => CodeStatus::Active, 'expiresAt' => Carbon::now()->subDay()]),
        ], usableOnly: true);

        // effectiveStatus 가 과거 expiresAt 로 인해 Expired 가 되어 skip
        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['skipped']);
    }

    public function test_usable_only_false_stores_expired(): void
    {
        $this->game();
        $stats = $this->sync->sync([$this->dto(['status' => CodeStatus::Expired])], usableOnly: false);
        $this->assertSame(1, $stats['created']);
        $this->assertSame(CodeStatus::Expired, RedeemCode::first()->status);
    }

    public function test_usable_only_existing_still_updated_to_expired(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['status' => CodeStatus::Active, 'source' => 'ennead'])]);
        // 기존 코드가 만료 보고를 받으면 usableOnly 여도 expired 로 갱신(skip 아님)
        $stats = $this->sync->sync([$this->dto(['status' => CodeStatus::Expired, 'source' => 'seria'])], usableOnly: true);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(CodeStatus::Expired, RedeemCode::first()->status);
    }

    // ---------------------------------------------------------------- 빈값만 채움
    public function test_rewards_and_expiry_only_fill_when_empty(): void
    {
        $this->game();
        $firstExpiry = Carbon::now()->addDays(10);
        $this->sync->sync([$this->dto([
            'status' => CodeStatus::Active, 'rewards' => '원석 60', 'expiresAt' => $firstExpiry, 'source' => 'ennead',
        ])]);

        // 다른 출처가 다른 보상/만료일을 보고해도 기존(비어있지 않음)은 유지
        $this->sync->sync([$this->dto([
            'status' => CodeStatus::Active, 'rewards' => '다른 보상', 'expiresAt' => Carbon::now()->addDays(99), 'source' => 'seria',
        ])]);

        $code = RedeemCode::first();
        $this->assertSame('원석 60', $code->rewards);
        $this->assertSame($firstExpiry->format('Y-m-d'), $code->expires_at->format('Y-m-d'));
    }

    public function test_empty_rewards_gets_filled_on_resync(): void
    {
        $this->game();
        $this->sync->sync([$this->dto(['status' => CodeStatus::Active, 'rewards' => null, 'source' => 'ennead'])]);
        $this->sync->sync([$this->dto(['status' => CodeStatus::Active, 'rewards' => '나중 보상', 'source' => 'seria'])]);

        $this->assertSame('나중 보상', RedeemCode::first()->rewards);
    }

    // ---------------------------------------------------------------- skipped 분기
    public function test_sync_skips_unknown_game_or_blank_code(): void
    {
        $this->game();
        $stats = $this->sync->sync([
            $this->dto(['gameSlug' => 'unknown-slug', 'status' => CodeStatus::Active]),
            $this->dto(['code' => '   ', 'status' => CodeStatus::Active]),
        ]);
        $this->assertSame(0, $stats['created']);
        $this->assertSame(2, $stats['skipped']);
    }

    // ---------------------------------------------------------------- markExpiredPastDue
    public function test_mark_expired_past_due(): void
    {
        $this->game();
        // usableOnly=false 로 강제 저장한 뒤, 만료일 경과 처리 검증
        $this->sync->sync([
            $this->dto(['code' => 'PASTDUE111', 'status' => CodeStatus::Active, 'expiresAt' => Carbon::now()->addDays(5)]),
        ], usableOnly: false);

        // DB에서 직접 만료일을 과거로 당김(이미 active 상태)
        RedeemCode::where('code', 'PASTDUE111')->update(['expires_at' => Carbon::now()->subDays(2)]);

        $changed = $this->sync->markExpiredPastDue();
        $this->assertSame(1, $changed);
        $this->assertSame(CodeStatus::Expired, RedeemCode::where('code', 'PASTDUE111')->first()->status);
    }

    public function test_mark_expired_past_due_ignores_null_expiry_and_already_expired(): void
    {
        $this->game();
        $this->sync->sync([
            $this->dto(['code' => 'NOEXPIRY11', 'status' => CodeStatus::Active, 'expiresAt' => null]),
        ], usableOnly: false);
        $this->sync->sync([
            $this->dto(['code' => 'ALREADYEXP', 'status' => CodeStatus::Expired]),
        ], usableOnly: false);

        $this->assertSame(0, $this->sync->markExpiredPastDue());
    }
}
