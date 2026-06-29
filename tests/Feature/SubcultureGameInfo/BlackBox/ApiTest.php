<?php

namespace Tests\Feature\SubcultureGameInfo\BlackBox;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 블랙박스: JSON API GET /api/subculture-game-info/codes 의 입력(쿼리)→출력(JSON) 계약.
 * 내부 서비스/모델 구조에 의존하지 않고 HTTP 응답 본문만으로 동작을 검증한다.
 * (DB 시드는 테스트 입력 준비일 뿐 — 검증은 응답 JSON 기준)
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/subculture-game-info/codes';

    private function game(string $slug, array $o = []): Game
    {
        return Game::create([
            'slug' => $slug,
            'name' => $o['name'] ?? strtoupper($slug),
            'icon' => $o['icon'] ?? '🎮',
            'redeem_url_template' => $o['redeem_url_template'] ?? null,
            'redeem_note' => $o['redeem_note'] ?? null,
            'region_default' => $o['region_default'] ?? 'global',
            'sort' => $o['sort'] ?? 1,
            'active_flg' => $o['active_flg'] ?? true,
        ]);
    }

    private function code(Game $game, array $o = []): RedeemCode
    {
        return RedeemCode::create([
            'subculture_game_id' => $game->id,
            'code' => $o['code'] ?? 'CODE'.random_int(1000, 9999),
            'region' => ($o['region'] ?? CodeRegion::Global)->value,
            'rewards' => $o['rewards'] ?? null,
            'source' => $o['source'] ?? 'ennead',
            'source_type' => ($o['source_type'] ?? SourceType::Aggregator)->value,
            'source_url' => $o['source_url'] ?? 'https://src.test',
            'seen_sources' => $o['seen_sources'] ?? [$o['source'] ?? 'ennead'],
            'corroboration_count' => $o['corroboration_count'] ?? 1,
            'status' => ($o['status'] ?? CodeStatus::Unverified)->value,
            'found_at' => $o['found_at'] ?? Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'expires_at' => $o['expires_at'] ?? null,
            'verified_at' => $o['verified_at'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- 응답 구조
    public function test_returns_envelope_shape_and_meta_total_matches_data(): void
    {
        $g = $this->game('genshin', ['name' => '원신']);
        $this->code($g, ['code' => 'AAAA1111', 'status' => CodeStatus::Active]);
        $this->code($g, ['code' => 'BBBB2222', 'status' => CodeStatus::Active]);

        $res = $this->getJson(self::ENDPOINT);

        $res->assertOk()
            ->assertJsonStructure([
                'data' => [['game' => ['slug', 'name', 'icon'], 'code', 'region', 'region_label',
                    'rewards', 'status', 'status_label', 'verified', 'corroboration_count',
                    'seen_sources', 'source', 'source_type', 'source_url', 'redeem_url',
                    'redeem_note', 'expires_at', 'days_left', 'found_at']],
                'meta' => ['total'],
            ]);

        $this->assertSame(2, $res->json('meta.total'));
        $this->assertCount($res->json('meta.total'), $res->json('data'));
    }

    // ---------------------------------------------------------------- expired 필터
    public function test_default_excludes_expired_codes(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['code' => 'USABLE111', 'status' => CodeStatus::Active]);
        $this->code($g, ['code' => 'EXPIRED11', 'status' => CodeStatus::Expired]);
        $this->code($g, ['code' => 'PASTDUE11', 'status' => CodeStatus::Active, 'expires_at' => Carbon::now()->subDay()]);

        $codes = collect($this->getJson(self::ENDPOINT)->json('data'))->pluck('code');

        $this->assertContains('USABLE111', $codes);
        $this->assertNotContains('EXPIRED11', $codes);
        $this->assertNotContains('PASTDUE11', $codes);
    }

    public function test_expired_param_includes_expired_codes(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['code' => 'USABLE111', 'status' => CodeStatus::Active]);
        $this->code($g, ['code' => 'EXPIRED11', 'status' => CodeStatus::Expired]);

        $codes = collect($this->getJson(self::ENDPOINT.'?expired=1')->json('data'))->pluck('code');

        $this->assertContains('USABLE111', $codes);
        $this->assertContains('EXPIRED11', $codes);
    }

    // ---------------------------------------------------------------- community 필터
    public function test_community_param_zero_excludes_community_sources(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['code' => 'AGGCODE11', 'status' => CodeStatus::Active, 'source_type' => SourceType::Aggregator]);
        $this->code($g, ['code' => 'COMMCODE1', 'status' => CodeStatus::Unverified, 'source' => 'dc', 'source_type' => SourceType::Community]);

        $all = collect($this->getJson(self::ENDPOINT)->json('data'))->pluck('code');
        $this->assertContains('COMMCODE1', $all, '기본(community=1)에서는 커뮤니티 코드 포함');

        $mainOnly = collect($this->getJson(self::ENDPOINT.'?community=0')->json('data'));
        $codes = $mainOnly->pluck('code');
        $this->assertContains('AGGCODE11', $codes);
        $this->assertNotContains('COMMCODE1', $codes);
        // 응답에 community source_type 이 하나도 없어야 한다
        $this->assertEmpty($mainOnly->where('source_type', 'community')->all());
    }

    // ---------------------------------------------------------------- verified 필터
    public function test_verified_param_returns_only_cross_verified_codes(): void
    {
        $g = $this->game('genshin');
        // active 확정 → verified
        $this->code($g, ['code' => 'ACTIVE111', 'status' => CodeStatus::Active, 'corroboration_count' => 1]);
        // 2개 출처 교차검증 → verified
        $this->code($g, ['code' => 'CORROB222', 'status' => CodeStatus::Unverified, 'corroboration_count' => 2, 'seen_sources' => ['html', 'dc']]);
        // 단일 출처 미검증 → 제외
        $this->code($g, ['code' => 'SINGLE333', 'status' => CodeStatus::Unverified, 'corroboration_count' => 1]);

        $data = collect($this->getJson(self::ENDPOINT.'?verified=1')->json('data'));
        $codes = $data->pluck('code');

        $this->assertContains('ACTIVE111', $codes);
        $this->assertContains('CORROB222', $codes);
        $this->assertNotContains('SINGLE333', $codes);
        // verified=1 응답의 모든 항목은 verified=true
        foreach ($data as $row) {
            $this->assertTrue($row['verified'], "{$row['code']} 는 verified 여야 함");
        }
    }

    // ---------------------------------------------------------------- game 필터
    public function test_game_param_returns_only_that_game(): void
    {
        $genshin = $this->game('genshin', ['name' => '원신']);
        $starrail = $this->game('starrail', ['name' => '스타레일', 'sort' => 2]);
        $this->code($genshin, ['code' => 'GENS11111', 'status' => CodeStatus::Active]);
        $this->code($starrail, ['code' => 'STAR22222', 'status' => CodeStatus::Active]);

        $res = $this->getJson(self::ENDPOINT.'?game=genshin');
        $data = collect($res->json('data'));

        $this->assertSame(['genshin'], $data->pluck('game.slug')->unique()->values()->all());
        $this->assertContains('GENS11111', $data->pluck('code'));
        $this->assertNotContains('STAR22222', $data->pluck('code'));
        $this->assertSame($data->count(), $res->json('meta.total'));
    }

    // ---------------------------------------------------------------- 항목 필드 계약
    public function test_redeem_url_substitutes_code_for_template_games(): void
    {
        $g = $this->game('genshin', [
            'redeem_url_template' => 'https://genshin.hoyoverse.com/ko/gift?code={code}',
        ]);
        $this->code($g, ['code' => 'GENSHINABC', 'status' => CodeStatus::Active]);

        $row = $this->getJson(self::ENDPOINT)->json('data.0');

        $this->assertSame('https://genshin.hoyoverse.com/ko/gift?code=GENSHINABC', $row['redeem_url']);
    }

    public function test_redeem_url_null_and_note_present_for_ingame_games(): void
    {
        $g = $this->game('bluearchive', [
            'redeem_url_template' => null,
            'redeem_note' => '게임 내 [계정 > 쿠폰]에서 입력',
        ]);
        $this->code($g, ['code' => 'BLUECODE11', 'status' => CodeStatus::Active]);

        $row = $this->getJson(self::ENDPOINT)->json('data.0');

        $this->assertNull($row['redeem_url']);
        $this->assertSame('게임 내 [계정 > 쿠폰]에서 입력', $row['redeem_note']);
    }

    public function test_days_left_is_integer_when_expiry_known_and_null_otherwise(): void
    {
        $g = $this->game('genshin');
        $this->code($g, ['code' => 'WITHEXP111', 'status' => CodeStatus::Active, 'expires_at' => Carbon::now()->addDays(5)]);
        $this->code($g, ['code' => 'NOEXP1111', 'status' => CodeStatus::Active, 'expires_at' => null]);

        $data = collect($this->getJson(self::ENDPOINT)->json('data'))->keyBy('code');

        $this->assertIsInt($data['WITHEXP111']['days_left']);
        $this->assertGreaterThanOrEqual(4, $data['WITHEXP111']['days_left']);
        $this->assertNull($data['NOEXP1111']['days_left']);
        $this->assertNotNull($data['WITHEXP111']['expires_at']);
        $this->assertNull($data['NOEXP1111']['expires_at']);
    }

    public function test_item_includes_rewards_and_corroboration_count(): void
    {
        $g = $this->game('genshin');
        $this->code($g, [
            'code' => 'RICHCODE11', 'status' => CodeStatus::Active,
            'rewards' => '원석 60, 모라 50000',
            'corroboration_count' => 3, 'seen_sources' => ['ennead', 'seria', 'html'],
        ]);

        $row = $this->getJson(self::ENDPOINT)->json('data.0');

        $this->assertSame('원석 60, 모라 50000', $row['rewards']);
        $this->assertSame(3, $row['corroboration_count']);
        $this->assertSame(['ennead', 'seria', 'html'], $row['seen_sources']);
        $this->assertSame('글로벌', $row['region_label']);
    }

    public function test_empty_when_no_codes(): void
    {
        $res = $this->getJson(self::ENDPOINT);
        $res->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame(0, $res->json('meta.total'));
    }
}
