<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use Carbon\Carbon;

/**
 * 게임 공식 트위터(X) — nitter RSS 로 최근 트윗을 받아 코드를 수집한다.
 * (X 본 사이트는 로그인 없이는 막혀 있어, 공개 프런트엔드 nitter 의 RSS 를 쓴다.)
 *
 * 한계(설계상 감수):
 *  - nitter 인스턴스는 가용성이 불안정하다 → 실패 시 빈 배열로 무해하게 폴백.
 *  - 코드를 이미지로만 올리는 경우는 RSS 텍스트에 없어 수집 불가.
 * 정밀도를 위해 '교환 코드/쿠폰 코드' 같은 코드 마커가 있는 트윗에서만 코드 토큰을 뽑고,
 * 같은 트윗의 '유효 기간 … 까지' 문구에서 만료일도 파싱한다(이미 지난 코드는 만료 처리됨).
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

        foreach ($this->tweets($xml) as $tweet) {
            if (! $this->looksLikeCodeTweet($tweet['text'])) {
                continue;
            }

            $expiresAt = $this->parseTweetExpiry($tweet['text'], $tweet['date']);
            // 만료일이 있으면 그에 따라(이미 지났으면 만료), 없으면 미검증.
            $status = match (true) {
                $expiresAt !== null && $expiresAt->isPast() => CodeStatus::Expired,
                $expiresAt !== null => CodeStatus::Active,
                default => CodeStatus::Unverified,
            };

            foreach ($this->extractCodeTokensFromText($tweet['text']) as $code) {
                $byCode[strtoupper($code)] ??= new CollectedCodeDto(
                    gameSlug: $gameSlug,
                    code: $code,
                    sourceType: SourceType::Aggregator,
                    source: 'twitter',
                    region: $region,
                    status: $status,
                    sourceUrl: $sourceUrl,
                    expiresAt: $expiresAt,
                );
            }
        }

        return array_values($byCode);
    }

    /**
     * RSS <item> 들의 (트윗 본문, 작성일)을 추출한다.
     *
     * @return array<int, array{text:string, date:?Carbon}>
     */
    private function tweets(string $xml): array
    {
        if (! preg_match_all('#<item>(.*?)</item>#s', $xml, $items)) {
            return [];
        }

        $out = [];
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
            if ($text === '') {
                continue;
            }

            $date = null;
            if (preg_match('#<pubDate>(.*?)</pubDate>#s', $item, $m)) {
                try {
                    $date = Carbon::parse(trim($m[1]));
                } catch (\Throwable) {
                    $date = null;
                }
            }
            $out[] = ['text' => $text, 'date' => $date];
        }

        return $out;
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

    /**
     * 트윗의 '유효 기간 … 까지' 문구에서 만료 일시를 파싱한다(KST). 만료 맥락의 날짜만 채택.
     * 연도가 없으면 트윗 작성연도를 쓴다. 못 찾으면 null.
     */
    private function parseTweetExpiry(string $text, ?Carbon $tweetedAt): ?Carbon
    {
        $tz = config('app.timezone', 'Asia/Seoul');

        // 1) 연도 포함 + 만료 맥락: '2026년 6월 29일 00:59까지' / '유효 기간은 2026년 6월 29일'
        if (preg_match('/(?:유효\s*기간|기한)[^0-9]{0,8}(\d{4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일(?:\s*(\d{1,2})\s*:\s*(\d{2}))?/u', $text, $m)
            || preg_match('/(\d{4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일(?:\s*(\d{1,2})\s*:\s*(\d{2}))?\s*까지/u', $text, $m)) {
            return $this->buildExpiry((int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? null, $m[5] ?? null, $tz);
        }

        // 2) 연도 없음 + 만료 맥락: 'M월 D일 (HH:MM) 까지' → 트윗 작성연도 사용
        if (preg_match('/(\d{1,2})\s*월\s*(\d{1,2})\s*일(?:\s*(\d{1,2})\s*:\s*(\d{2}))?\s*까지/u', $text, $m)) {
            $year = $tweetedAt?->year ?? (int) now()->year;

            return $this->buildExpiry($year, (int) $m[1], (int) $m[2], $m[3] ?? null, $m[4] ?? null, $tz);
        }

        return null;
    }

    private function buildExpiry(int $year, int $month, int $day, ?string $hour, ?string $min, string $tz): ?Carbon
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        try {
            // 시각이 없으면 그 날 끝(23:59)으로 본다.
            return Carbon::create($year, $month, $day, $hour !== null ? (int) $hour : 23, $min !== null ? (int) $min : 59, 0, $tz);
        } catch (\Throwable) {
            return null;
        }
    }
}
