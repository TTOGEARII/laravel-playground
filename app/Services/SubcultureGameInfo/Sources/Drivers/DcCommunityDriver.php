<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 디씨인사이드 마이너 갤러리 — 보조 신호(community).
 * 코드 키워드가 든 글 제목에서만 코드 토큰을 미검증으로 수집한다.
 */
class DcCommunityDriver extends AbstractSourceDriver
{
    public function driverKey(): string
    {
        return 'dc';
    }

    public function isCommunity(): bool
    {
        return true;
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.dc');
        $galleryId = $cfg['galleries'][$gameSlug] ?? null;
        if ($galleryId === null) {
            return [];
        }

        $html = $this->getHtml($cfg['base'], ['id' => $galleryId]);
        if ($html === null) {
            return [];
        }

        $listUrl = $cfg['base'].'?id='.$galleryId;
        $region = $this->regionFor($gameSlug);

        return array_map(fn ($code) => new CollectedCodeDto(
            gameSlug: $gameSlug,
            code: $code,
            sourceType: SourceType::Community,
            source: $this->driverKey(),
            region: $region,
            status: CodeStatus::Unverified,
            sourceUrl: $listUrl,
        ), $this->extractCodesFromLinkTitles($html));
    }
}
