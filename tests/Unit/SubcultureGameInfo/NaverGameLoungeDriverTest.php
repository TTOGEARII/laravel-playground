<?php

namespace Tests\Unit\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\Drivers\NaverGameLoungeDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NaverGameLoungeDriver 화이트박스: 게시판 목록 → 쿠폰 글에서 코드/보상/만료 추출.
 * 본문은 네이버 에디터 document JSON, '쿠폰 코드 …' 마커 뒤 토큰(대소문자 보존).
 */
class NaverGameLoungeDriverTest extends TestCase
{
    private function fakeLounge(array $feeds, array $boards = [['boardId' => 31, 'boardName' => '🧾 쿠폰 게시판']]): void
    {
        Http::fake([
            'comm-api.game.naver.com/nng_main/v1/lounge/Trickcal/board' => Http::response(['content' => $boards], 200),
            'comm-api.game.naver.com/nng_main/v1/community/lounge/Trickcal/feed*' => Http::response(
                ['content' => ['feeds' => $feeds]], 200
            ),
        ]);
    }

    private function couponFeed(string $title, array $textLines, string $createdDate = '20260622120119'): array
    {
        $components = array_map(fn ($t) => ['text' => $t], $textLines);

        return [
            'feed' => [
                'feedId' => 7831467,
                'title' => $title,
                'createdDate' => $createdDate,
                'contents' => json_encode(['document' => ['components' => $components]]),
            ],
            'user' => ['nickname' => 'GM아멜리아'],
        ];
    }

    public function test_extracts_code_reward_and_future_expiry_as_active(): void
    {
        Carbon::setTestNow('2026-06-25 10:00:00');
        $this->fakeLounge([
            $this->couponFeed('[쿠폰] 1000일 기념 쿠폰 안내(~6월 28일)', [
                '안녕하세요 교주님들! 보상을 수령해 주세요.',
                '🎟 쿠폰 코드(대소문자를 구분합니다) GYOJU1KDAYS',
                '🎁 쿠폰 보상 - 10,000,000 골드',
                '🗓 사용 기한 - 6월 22일 ~ 6월 28일 23:59',
            ]),
        ]);

        $dtos = (new NaverGameLoungeDriver)->collect('trickcal', []);
        $this->assertCount(1, $dtos);
        $dto = $dtos[0];

        $this->assertSame('GYOJU1KDAYS', $dto->code);  // 대소문자 보존
        $this->assertSame(SourceType::Aggregator, $dto->sourceType);
        $this->assertSame('naver', $dto->source);
        $this->assertSame(CodeStatus::Active, $dto->status);
        $this->assertSame('2026-06-28', $dto->expiresAt?->format('Y-m-d'));
        $this->assertStringContainsString('골드', (string) $dto->rewards);
        $this->assertStringNotContainsString('수령', (string) $dto->rewards);  // 인트로 오매칭 아님

        Carbon::setTestNow();
    }

    public function test_past_expiry_marks_expired(): void
    {
        Carbon::setTestNow('2026-06-25 10:00:00');
        $this->fakeLounge([
            $this->couponFeed('[쿠폰] 현충일 추념 쿠폰 안내(~6월 13일)', [
                '◈ 쿠폰 코드(대소문자를 구분합니다) ◈ MEMORIAL',
                '◈ 사용 기한 ◈ - 6월 6일 ~ 6월 13일 23:59',
            ]),
        ]);

        $dtos = (new NaverGameLoungeDriver)->collect('trickcal', []);
        $this->assertSame('MEMORIAL', $dtos[0]->code);
        $this->assertSame(CodeStatus::Expired, $dtos[0]->status);

        Carbon::setTestNow();
    }

    public function test_skips_post_without_code_marker(): void
    {
        $this->fakeLounge([
            $this->couponFeed('[안내] 콜라보 사전예약 안내', [
                '사전예약 이벤트를 진행합니다. 많은 참여 바랍니다.',
            ]),
        ]);

        $this->assertSame([], (new NaverGameLoungeDriver)->collect('trickcal', []));
    }

    public function test_notice_board_only_processes_coupon_titled_posts(): void
    {
        // 공지 게시판(쿠폰 전용 아님): 제목에 쿠폰/코드 없는 글은 본문에 코드가 있어도 건너뛴다.
        Http::fake([
            'comm-api.game.naver.com/nng_main/v1/lounge/Trickcal/board' => Http::response([
                'content' => [['boardId' => 3, 'boardName' => '📢공지사항']],
            ], 200),
            'comm-api.game.naver.com/nng_main/v1/community/lounge/Trickcal/feed*' => Http::response([
                'content' => ['feeds' => [
                    $this->couponFeed('[점검] 정기 점검 안내', ['쿠폰 코드 SHOULDNOTSHOW']),
                ]],
            ], 200),
        ]);

        $this->assertSame([], (new NaverGameLoungeDriver)->collect('trickcal', []));
    }

    public function test_returns_empty_for_unmapped_game(): void
    {
        Http::fake();
        $this->assertSame([], (new NaverGameLoungeDriver)->collect('genshin', []));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- searchCode(검증)
    public function test_search_code_found_with_expired_hint_for_past_coupon(): void
    {
        Carbon::setTestNow('2026-06-25 10:00:00');
        $this->fakeLounge([
            $this->couponFeed('[쿠폰] 현충일 쿠폰(~6월 13일)', [
                '쿠폰 코드(대소문자를 구분합니다) MEMORIAL',
                '사용 기한 - 6월 6일 ~ 6월 13일 23:59',
            ]),
        ]);

        $hit = (new NaverGameLoungeDriver)->searchCode('trickcal', 'MEMORIAL');
        $this->assertNotNull($hit);
        $this->assertTrue($hit->found);
        $this->assertTrue($hit->expiredHint);   // 라운지 글 사용기한 지남 → 만료 단서
        $this->assertSame('naver-search', $hit->source);

        Carbon::setTestNow();
    }

    public function test_search_code_miss_for_code_not_in_lounge(): void
    {
        $this->fakeLounge([
            $this->couponFeed('[쿠폰] 쿠폰 안내(~12월 31일)', ['쿠폰 코드 REALCODE2026']),
        ]);

        $hit = (new NaverGameLoungeDriver)->searchCode('trickcal', 'NOTHERE99');
        $this->assertNotNull($hit);
        $this->assertFalse($hit->found);
    }

    public function test_search_code_null_for_game_without_lounge(): void
    {
        Http::fake();
        $this->assertNull((new NaverGameLoungeDriver)->searchCode('genshin', 'GENSHINGIFT'));
        Http::assertNothingSent();
    }
}
