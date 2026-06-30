<?php

namespace Tests\Feature;

use App\Enums\InquiryCategory;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_page_renders(): void
    {
        $this->get('/inquiry')
            ->assertOk()
            ->assertSee('문의하기')
            ->assertSee('문의 유형');
    }

    public function test_valid_submission_creates_inquiry_and_redirects(): void
    {
        $payload = [
            'category' => 'bug',
            'name' => '또기',
            'contact' => 'test@example.com',
            'subject' => '오타쿠샵 정렬 오류',
            'message' => '가격 낮은순 정렬이 동작하지 않습니다.',
        ];

        $this->post('/inquiry', $payload)
            ->assertRedirect(route('inquiry.create'))
            ->assertSessionHas('inquiry_success');

        $this->assertDatabaseCount('inquiries', 1);
        $inquiry = Inquiry::first();
        $this->assertSame('또기', $inquiry->name);
        $this->assertSame(InquiryCategory::Bug, $inquiry->category);
        $this->assertSame(InquiryStatus::Received, $inquiry->status);  // 기본 접수 상태
        $this->assertNull($inquiry->user_id);                          // 비로그인
    }

    public function test_logged_in_user_is_linked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/inquiry', [
            'category' => 'general',
            'name' => '회원',
            'subject' => '제목입니다',
            'message' => '로그인 상태에서 남기는 문의 내용입니다.',
        ])->assertRedirect();

        $this->assertSame($user->id, Inquiry::first()->user_id);
    }

    public function test_validation_rejects_short_message_and_missing_fields(): void
    {
        $this->post('/inquiry', [
            'category' => 'general',
            'name' => '',
            'subject' => '',
            'message' => '짧음',  // 10자 미만
        ])->assertSessionHasErrors(['name', 'subject', 'message']);

        $this->assertDatabaseCount('inquiries', 0);
    }

    public function test_invalid_category_is_rejected(): void
    {
        $this->post('/inquiry', [
            'category' => 'spam',
            'name' => '또기',
            'subject' => '제목',
            'message' => '유효하지 않은 카테고리 테스트입니다.',
        ])->assertSessionHasErrors('category');
    }
}
