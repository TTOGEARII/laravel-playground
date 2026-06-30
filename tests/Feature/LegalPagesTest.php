<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_terms_page_renders(): void
    {
        $this->get('/terms')->assertOk()->assertSee('이용약관')->assertSee('서비스의 성격');
    }

    public function test_privacy_page_renders(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('개인정보처리방침')
            ->assertSee('소셜 로그인');  // 카카오/구글 대비 항목
    }

    public function test_license_page_renders(): void
    {
        $this->get('/license')->assertOk()->assertSee('라이센스')->assertSee('오픈소스');
    }

    public function test_home_footer_links_to_legal_pages(): void
    {
        $this->get('/')
            ->assertSee(route('legal.terms'))
            ->assertSee(route('legal.privacy'))
            ->assertSee(route('legal.license'))
            ->assertSee(route('inquiry.create'));
    }
}
