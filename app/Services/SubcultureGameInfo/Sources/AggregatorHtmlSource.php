<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 블루아카(mollulog)·명조(wuthering.gg)·트릭컬(쿠폰몽) 등 정적 HTML 정리 사이트.
 * 페이지 chrome/산문에 섞인 잡토큰을 줄이기 위해, 코드가 주로 들어가는
 * 강조/표 셀 계열 요소(<strong>,<b>,<code>,<td>,<mark>,<h3~4>)의 텍스트에서만
 * 코드형 토큰(대문자+숫자)을 뽑는다. 상태는 미검증으로 둔다(만료 판별은 별도).
 */
class AggregatorHtmlSource extends AbstractCodeSource
{
    public function key(): string
    {
        return 'aggregator';
    }

    public function fetch(): array
    {
        $map = config('subculture-game-info.sources.aggregators', []);
        $out = [];

        foreach ($map as $gameSlug => $urls) {
            $region = $this->regionFor($gameSlug);

            // 게임당 URL 1개(문자열) 또는 여러 개(배열) 모두 지원 — 여러 개면 폴백/보강
            foreach ((array) $urls as $url) {
                $html = $this->getHtml($url);
                if ($html === null) {
                    continue;
                }

                foreach ($this->extractCodes($html) as $code) {
                    $out[] = new CollectedCodeDto(
                        gameSlug: $gameSlug,
                        code: $code,
                        sourceType: SourceType::Aggregator,
                        source: $this->sourceKeyFor($url),
                        region: $region,
                        status: CodeStatus::Unverified,
                        sourceUrl: $url,
                    );
                }
            }
        }

        return $out;
    }

    /** 강조/표 요소 텍스트에서 코드형 토큰만 추출(중복 제거, 원문 케이스 보존). */
    public function extractCodes(string $html): array
    {
        $found = [];

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query('//strong|//b|//code|//td|//mark|//h3|//h4|//span');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $token = trim($node->textContent);
                if ($this->looksLikeCode($token)) {
                    $found[strtoupper($token)] = $token; // 대문자 키로 중복 제거
                }
            }
        }

        return array_values($found);
    }

    private function regionFor(string $gameSlug): CodeRegion
    {
        $def = config("subculture-game-info.games.{$gameSlug}.region_default", 'global');

        return CodeRegion::tryFrom($def) ?? CodeRegion::Global;
    }

    /** URL 호스트로 소스 키 부여(mollulog, wuthering, honeybeejoa ...). */
    private function sourceKeyFor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'aggregator';
        $host = preg_replace('/^www\./', '', $host);
        $label = explode('.', (string) $host)[0] ?? 'aggregator';

        return substr($label, 0, 40);
    }
}
