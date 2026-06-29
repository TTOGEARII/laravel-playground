<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 디씨인사이드 마이너 갤러리 — 보조 신호(community).
 * 갤러리 글 목록에서 "리딤/쿠폰/코드/교환"이 들어간 제목을 추려, 그 제목 안의
 * 코드형 토큰만 미검증(unverified)으로 수집한다. 본문/댓글까지는 긁지 않는다(노이즈/약관 고려).
 */
class DcCommunitySource extends AbstractCodeSource
{
    private const TITLE_KEYWORDS = ['리딤', '쿠폰', '코드', '교환', 'coupon', 'code', 'redeem'];

    public function key(): string
    {
        return 'dc';
    }

    public function fetch(): array
    {
        $cfg = config('subculture-game-info.sources.community.dc');
        if (empty($cfg['enabled'])) {
            return [];
        }

        $base = $cfg['base'];
        $out = [];

        foreach ($cfg['galleries'] as $gameSlug => $galleryId) {
            $listUrl = $base.'?id='.$galleryId;
            $html = $this->getHtml($base, ['id' => $galleryId]);
            if ($html === null) {
                continue;
            }

            $region = CodeRegion::tryFrom(
                config("subculture-game-info.games.{$gameSlug}.region_default", 'global')
            ) ?? CodeRegion::Global;

            foreach ($this->extractFromTitles($html) as $code) {
                $out[] = new CollectedCodeDto(
                    gameSlug: $gameSlug,
                    code: $code,
                    sourceType: SourceType::Community,
                    source: $this->key(),
                    region: $region,
                    status: CodeStatus::Unverified,
                    sourceUrl: $listUrl,
                );
            }
        }

        return $out;
    }

    /** 코드 키워드가 들어간 링크(제목) 텍스트에서만 코드 토큰 추출. */
    public function extractFromTitles(string $html): array
    {
        $found = [];

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $links = $xpath->query('//a');
        if ($links === false) {
            return [];
        }

        foreach ($links as $a) {
            $title = trim($a->textContent);
            if ($title === '' || ! $this->hasKeyword($title)) {
                continue;
            }
            foreach ($this->extractCodeTokensFromText($title) as $token) {
                $found[strtoupper($token)] = $token;
            }
        }

        return array_values($found);
    }

    private function hasKeyword(string $title): bool
    {
        $lower = mb_strtolower($title);
        foreach (self::TITLE_KEYWORDS as $kw) {
            if (mb_strpos($lower, mb_strtolower($kw)) !== false) {
                return true;
            }
        }

        return false;
    }
}
