<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\GuidePost;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * 공략글(GuidePost) 링크에서 본문 텍스트(+본문 속 이미지 URL)를 가져온다.
 * 소스별 본문 영역 셀렉터는 config raids.substitutes.body_selectors 에 두어
 * 새 소스(더쿠/루리웹 등)는 설정 추가만으로 확장한다.
 * 글 하나의 실패가 전체 추출을 죽이지 않도록 실패는 로그 + null 로 처리한다.
 */
class GuideBodyFetcher
{
    use FetchesWebContent;

    /** Gemini 멀티모달이 받는 이미지 MIME (gif 등은 미지원이라 스킵) */
    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function fetch(GuidePost $post): ?string
    {
        return $this->fetchContent($post)['text'] ?? null;
    }

    /**
     * 본문 텍스트 + 본문 영역의 이미지 URL 목록.
     * 공략이 인포그래픽(스크린샷) 한 장인 글은 텍스트가 비어도 이미지로 분석할 수 있다.
     *
     * @return array{text: ?string, image_urls: list<string>}|null 페이지 자체를 못 가져오면 null
     */
    public function fetchContent(GuidePost $post): ?array
    {
        $selectors = config('subculture-game-info.raids.substitutes.body_selectors', []);
        $selector = $selectors[$post->source] ?? null;
        if ($selector === null) {
            Log::info('[SGI-SUB] 본문 셀렉터 미정의 소스 — 스킵', ['source' => $post->source, 'url' => $post->url]);

            return null;
        }

        $html = $this->getHtml($post->url);
        if ($html === null) {
            return null;
        }

        try {
            $xp = $this->xpath($html);
            $node = $xp->query((new CssSelectorConverter)->toXPath($selector))?->item(0);
            if (! $node instanceof \DOMElement) {
                Log::warning('[SGI-SUB] 본문 영역을 찾지 못함(셀렉터 확인 필요)', ['url' => $post->url, 'selector' => $selector]);

                return null;
            }

            $text = $this->stripToText($node->ownerDocument?->saveHTML($node) ?: '');

            return [
                'text' => $text === '' ? null : $text,
                'image_urls' => $this->extractImageUrls($xp, $node),
            ];
        } catch (\Throwable $e) {
            Log::warning('[SGI-SUB] 본문 파싱 실패', ['url' => $post->url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 이미지 URL 을 내려받아 Gemini inlineData 형태(base64)로 변환한다.
     * 커뮤니티 CDN 은 referer 없으면 403 을 주는 경우가 많아 글 URL 을 referer 로 붙인다.
     *
     * @param  list<string>  $urls
     * @return array<int, array{mime_type: string, data: string}>
     */
    public function downloadImages(array $urls, string $referer, int $max, int $maxBytes): array
    {
        $images = [];

        foreach ($urls as $url) {
            if (count($images) >= $max) {
                break;
            }

            try {
                $res = $this->http()->withHeaders(['Referer' => $referer, 'Accept' => 'image/*'])->get($url);
                if (! $res->successful()) {
                    Log::info('[SGI-SUB] 이미지 다운로드 실패', ['url' => $url, 'status' => $res->status()]);

                    continue;
                }

                $mime = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));
                $body = $res->body();
                if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true) || $body === '' || strlen($body) > $maxBytes) {
                    continue;
                }

                $images[] = ['mime_type' => $mime, 'data' => base64_encode($body)];
            } catch (\Throwable $e) {
                Log::info('[SGI-SUB] 이미지 다운로드 예외', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return $images;
    }

    /** 본문 영역의 img src 수집 — 프로토콜 상대(//) 보정, 이모티콘 등 비 http 는 제외. */
    private function extractImageUrls(\DOMXPath $xp, \DOMElement $node): array
    {
        $urls = [];
        foreach ($xp->query('.//img', $node) ?: [] as $img) {
            if (! $img instanceof \DOMElement) {
                continue;
            }
            $src = trim($img->getAttribute('data-src') ?: $img->getAttribute('src'));
            if ($src === '') {
                continue;
            }
            if (str_starts_with($src, '//')) {
                $src = 'https:'.$src;
            }
            if (preg_match('#^https?://#i', $src) === 1) {
                $urls[$src] = true;
            }
        }

        return array_keys($urls);
    }
}
