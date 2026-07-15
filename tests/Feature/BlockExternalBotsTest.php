<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 외부 봇 차단 미들웨어(BlockExternalBots) 동작 검증.
 * - 외부(공인 IP) 스크래퍼/자동봇 → 403
 * - 검색엔진·링크 미리보기 → 통과
 * - 내부(루프백·사설 대역) → UA 무관 통과(헬스체크 보호)
 * - 정상 브라우저 → 통과
 */
class BlockExternalBotsTest extends TestCase
{
    private const PUBLIC_IP = '203.0.113.10';   // 문서화용 공인 IP(TEST-NET-3)

    private const DOCKER_IP = '172.18.0.1';     // 배포 헬스체크가 찍히는 도커 게이트웨이

    /** 공인 IP + 스크래퍼 UA 는 막는다. */
    public function test_external_scraper_is_blocked(): void
    {
        foreach (['curl/8.7.1', 'python-requests/2.32.5', 'Go-http-client/1.1', 'Scrapy/2.11'] as $ua) {
            $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
                ->get('/', ['User-Agent' => $ua])
                ->assertForbidden();
        }
    }

    /** 공인 IP + AI 크롤러 UA 도 막는다. */
    public function test_external_ai_crawler_is_blocked(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)';

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => $ua])
            ->assertForbidden();
    }

    /** 공인 IP + 빈 UA(UA 를 지운 스크립트)도 막는다. */
    public function test_external_empty_user_agent_is_blocked(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => ''])
            ->assertForbidden();
    }

    /** 검색엔진 크롤러는 공인 IP 여도 통과시킨다(SEO 유지). */
    public function test_search_engine_is_allowed(): void
    {
        $googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $yeti = 'Mozilla/5.0 (compatible; Yeti/1.1; +https://naver.me/spd)'; // 네이버

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => $googlebot])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => $yeti])
            ->assertOk();
    }

    /** 내부(도커 게이트웨이) 요청은 봇 UA 여도 통과 — 배포 헬스체크 보호. */
    public function test_internal_request_is_never_blocked(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::DOCKER_IP])
            ->get('/', ['User-Agent' => 'curl/8.5.0'])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/', ['User-Agent' => 'curl/8.5.0'])
            ->assertOk();
    }

    /** 정상 브라우저는 통과. */
    public function test_regular_browser_is_allowed(): void
    {
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            .'Chrome/126.0.0.0 Safari/537.36';

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => $chrome])
            ->assertOk();
    }
}
