<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\GuidePost;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * 공략글(GuidePost) 링크에서 본문 텍스트를 가져온다.
 * 소스별 본문 영역 셀렉터는 config raids.substitutes.body_selectors 에 두어
 * 새 소스(더쿠/루리웹 등)는 설정 추가만으로 확장한다.
 *
 * 평문 HTTP 를 먼저 쓰고, 본문이 없거나 너무 짧으면(아카=JS 렌더/봇차단, 디씨=축약 페이지)
 * Playwright 사이드카로 렌더해 재시도한다. 글 하나의 실패가 전체 추출을 죽이지 않도록
 * 실패는 로그 + null 로 처리한다.
 */
class GuideBodyFetcher
{
    use FetchesWebContent;

    /** 이 길이 미만이면 차단·축약 페이지로 보고 브라우저 렌더로 재시도한다. */
    private const MIN_MEANINGFUL = 120;

    public function __construct(private CrawlerScriptRunner $browser) {}

    public function fetch(GuidePost $post): ?string
    {
        $selectors = config('subculture-game-info.raids.substitutes.body_selectors', []);
        $selector = $selectors[$post->source] ?? null;
        if ($selector === null) {
            Log::info('[SGI-SUB] 본문 셀렉터 미정의 소스 — 스킵', ['source' => $post->source, 'url' => $post->url]);

            return null;
        }

        // ① 평문 HTTP — 충분히 길면 그대로 사용(브라우저 렌더 비용 회피)
        $text = $this->extract($this->getHtml($post->url), $selector, $post->url);
        if ($text !== null && mb_strlen($text) >= self::MIN_MEANINGFUL) {
            return $text;
        }

        // ② 브라우저 렌더 폴백 — 셀렉터를 대기 조건으로. 더 길게 나오면 채택
        $rendered = $this->extract($this->browser->fetchHtml($post->url, $selector), $selector, $post->url);
        if ($rendered !== null && ($text === null || mb_strlen($rendered) > mb_strlen($text))) {
            return $rendered;
        }

        return $text; // 둘 다 짧아도 있는 쪽 반환(없으면 null)
    }

    /** HTML 에서 본문 셀렉터 영역의 텍스트를 뽑는다(실패 시 null). */
    private function extract(?string $html, string $selector, string $url): ?string
    {
        if ($html === null) {
            return null;
        }

        try {
            $node = $this->xpath($html)
                ->query((new CssSelectorConverter)->toXPath($selector))
                ?->item(0);
            if (! $node instanceof \DOMElement) {
                return null;
            }

            $text = $this->stripToText($node->ownerDocument?->saveHTML($node) ?: '');

            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            Log::warning('[SGI-SUB] 본문 파싱 실패', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
