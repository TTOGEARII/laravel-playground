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
 * 글 하나의 실패가 전체 추출을 죽이지 않도록 실패는 로그 + null 로 처리한다.
 */
class GuideBodyFetcher
{
    use FetchesWebContent;

    public function fetch(GuidePost $post): ?string
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
            $node = $this->xpath($html)
                ->query((new CssSelectorConverter)->toXPath($selector))
                ?->item(0);
            if (! $node instanceof \DOMElement) {
                Log::warning('[SGI-SUB] 본문 영역을 찾지 못함(셀렉터 확인 필요)', ['url' => $post->url, 'selector' => $selector]);

                return null;
            }

            $text = $this->stripToText($node->ownerDocument?->saveHTML($node) ?: '');

            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            Log::warning('[SGI-SUB] 본문 파싱 실패', ['url' => $post->url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
