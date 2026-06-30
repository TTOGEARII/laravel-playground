<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 게임 공식 트위터(X) — nitter RSS 로 최근 트윗을 받아 코드를 수집한다.
 * (X 본 사이트는 로그인 없이는 막혀 있어, 공개 프런트엔드 nitter 의 RSS 를 쓴다.)
 *
 * 한계(설계상 감수):
 *  - nitter 인스턴스는 가용성이 불안정하다 → 실패 시 빈 배열로 무해하게 폴백.
 *  - 코드를 이미지로만 올리는 경우는 RSS 텍스트에 없어 수집 불가.
 * 정밀도를 위해 '교환 코드/쿠폰 코드' 같은 코드 마커가 있는 트윗에서만 코드 토큰을 뽑는다.
 */
class TwitterDriver extends AbstractSourceDriver
{
    /** 이 문구가 든 트윗만 코드 트윗으로 보고 추출한다(노이즈 방지). */
    private const CODE_MARKERS = ['교환\s*코드', '쿠폰\s*코드', '리딤\s*코드', '선물\s*코드', '코드\s*입력', 'redeem\s*code', 'coupon\s*code', 'gift\s*code'];

    public function driverKey(): string
    {
        return 'twitter';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.twitter');
        $account = $cfg['accounts'][$gameSlug] ?? null;
        if ($account === null) {
            return [];
        }

        $base = rtrim($cfg['nitter_base'] ?? 'https://nitter.net', '/');
        $xml = $this->getHtml("{$base}/{$account}/rss");
        if ($xml === null) {
            return [];
        }

        $region = $this->regionFor($gameSlug);
        $sourceUrl = "https://x.com/{$account}";
        $byCode = [];

        foreach ($this->tweetTexts($xml) as $text) {
            if (! $this->looksLikeCodeTweet($text)) {
                continue;
            }
            foreach ($this->extractCodeTokensFromText($text) as $code) {
                // 공식 계정이지만 토큰 스캔이라 미검증으로 두고, 교차검증으로 신뢰도를 올린다.
                $byCode[strtoupper($code)] ??= new CollectedCodeDto(
                    gameSlug: $gameSlug,
                    code: $code,
                    sourceType: SourceType::Aggregator,
                    source: 'twitter',
                    region: $region,
                    status: CodeStatus::Unverified,
                    sourceUrl: $sourceUrl,
                );
            }
        }

        return array_values($byCode);
    }

    /**
     * RSS <item> 들의 트윗 본문(태그·CDATA 제거, 공백 정리)을 추출한다.
     *
     * @return string[]
     */
    private function tweetTexts(string $xml): array
    {
        if (! preg_match_all('#<item>(.*?)</item>#s', $xml, $items)) {
            return [];
        }

        $texts = [];
        foreach ($items[1] as $item) {
            $raw = '';
            if (preg_match('#<description>(.*?)</description>#s', $item, $m)) {
                $raw = $m[1];
            } elseif (preg_match('#<title>(.*?)</title>#s', $item, $m)) {
                $raw = $m[1];
            }
            $raw = preg_replace('#<!\[CDATA\[(.*?)\]\]>#s', '$1', $raw) ?? $raw;
            $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private function looksLikeCodeTweet(string $text): bool
    {
        foreach (self::CODE_MARKERS as $marker) {
            if (preg_match('/'.$marker.'/u', $text)) {
                return true;
            }
        }

        return false;
    }
}
