<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 외부 봇 차단 미들웨어(BlockExternalBots) 동작 검증.
 * - 공격 시그니처(LFI·XSS·경로탐색) → UA·IP 무관 403
 * - 차단 IP 목록(blocked_ips) → UA 무관 403
 * - 외부(공인 IP) 스크래퍼/자동봇 → 403
 * - 검색엔진·링크 미리보기 → 통과
 * - 내부(루프백·사설 대역) → 봇 UA 무관 통과(헬스체크 보호)
 * - 정상 브라우저 → 통과
 */
class BlockExternalBotsTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '203.0.113.10';   // 문서화용 공인 IP(TEST-NET-3)

    private const DOCKER_IP = '172.18.0.1';     // 배포 헬스체크가 찍히는 도커 게이트웨이

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        .'Chrome/126.0.0.0 Safari/537.36';

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
        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => self::CHROME])
            ->assertOk();
    }

    /** 브라우저 UA 로 위장해도 URL 에 공격 시그니처(LFI·경로탐색)가 있으면 차단. */
    public function test_attack_signature_is_blocked_even_with_browser_ua(): void
    {
        $payloads = [
            '/?file='.rawurlencode('php://filter/read=convert.base64-encode/resource=../.env'),
            '/?path='.rawurlencode('../../../../../../var/www/html/wp-config.php'),
            '/?q='.rawurlencode('""><script>alert(1)</script>'),
            '/?id='.rawurlencode("1' union select null,null--"),
        ];

        foreach ($payloads as $uri) {
            $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
                ->get($uri, ['User-Agent' => self::CHROME])
                ->assertForbidden();
        }
    }

    /** 공격 시그니처는 내부 IP 라도 차단(정상 내부 트래픽엔 이런 패턴이 없다). */
    public function test_attack_signature_is_blocked_for_internal_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::DOCKER_IP])
            ->get('/?file='.rawurlencode('../../../../etc/passwd'), ['User-Agent' => self::CHROME])
            ->assertForbidden();
    }

    /** 차단 목록(blocked_ips)에 등록된 IP 는 브라우저 UA·정상 URL 이어도 차단. */
    public function test_blocked_ip_is_denied(): void
    {
        BlockedIp::create(['ip' => self::PUBLIC_IP, 'reason' => '테스트']);
        Cache::forget(BlockedIp::CACHE_KEY);

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => self::CHROME])
            ->assertForbidden();
    }

    /** 차단 목록에 없는 IP 는 정상 통과(차단이 전역으로 새지 않음). */
    public function test_unblocked_ip_passes(): void
    {
        BlockedIp::create(['ip' => '198.51.100.7', 'reason' => '다른 IP']);
        Cache::forget(BlockedIp::CACHE_KEY);

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP])
            ->get('/', ['User-Agent' => self::CHROME])
            ->assertOk();
    }
}
