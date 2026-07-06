<?php

namespace App\Services\SubcultureGameInfo\Sources\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 실제 브라우저 UA 로 HTML/JSON 을 방어적으로 가져오는 공용 헬퍼.
 * 리딤코드 소스 드라이버(AbstractSourceDriver)와 공략글 드라이버가 공유한다.
 */
trait FetchesWebContent
{
    protected function http(): PendingRequest
    {
        $cfg = config('subculture-game-info.http');

        return Http::withHeaders([
            'User-Agent' => $cfg['user_agent'],
            'Accept-Language' => 'ko-KR,ko;q=0.9,en;q=0.8',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Upgrade-Insecure-Requests' => '1',
        ])
            ->timeout($cfg['timeout'] ?? 15)
            ->retry($cfg['retry'] ?? 2, 1500, throw: false);
    }

    protected function getHtml(string $url, array $query = []): ?string
    {
        try {
            $req = $this->http()->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']);
            // 빈 query 를 넘기면 Guzzle 이 URL의 기존 쿼리스트링을 덮어쓰므로 비어있으면 생략
            $res = $query === [] ? $req->get($url) : $req->get($url, $query);
            if (! $res->successful()) {
                Log::warning('[SGI] HTML fetch 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            return $res->body();
        } catch (\Throwable $e) {
            Log::warning('[SGI] HTML fetch 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function getJson(string $url, array $query = []): ?array
    {
        try {
            $req = $this->http()->withHeaders(['Accept' => 'application/json']);
            $res = $query === [] ? $req->get($url) : $req->get($url, $query);
            if (! $res->successful()) {
                Log::warning('[SGI] JSON fetch 실패', ['url' => $url, 'status' => $res->status()]);

                return null;
            }

            return $res->json();
        } catch (\Throwable $e) {
            Log::warning('[SGI] JSON fetch 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** DOMXPath 생성(UTF-8). */
    protected function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    protected function stripToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
