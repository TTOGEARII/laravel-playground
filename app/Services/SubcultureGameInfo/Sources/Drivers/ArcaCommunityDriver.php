<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 아카라이브 채널 — 보조 신호(community).
 * 실제 브라우저 UA면 정적 HTTP로 채널 글 목록 접근 가능(이전 403은 봇 UA 탓).
 * 코드 키워드가 든 글 제목에서만 코드 토큰을 미검증으로 수집한다.
 */
class ArcaCommunityDriver extends AbstractSourceDriver
{
    public function driverKey(): string
    {
        return 'arca';
    }

    public function isCommunity(): bool
    {
        return true;
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.arca');
        $channel = $cfg['channels'][$gameSlug] ?? null;
        if ($channel === null) {
            return [];
        }

        $url = rtrim($cfg['base'], '/').'/'.$channel;
        $html = $this->getHtml($url);
        if ($html === null) {
            return [];
        }

        $region = $this->regionFor($gameSlug);

        return array_map(fn ($code) => new CollectedCodeDto(
            gameSlug: $gameSlug,
            code: $code,
            sourceType: SourceType::Community,
            source: $this->driverKey(),
            region: $region,
            status: CodeStatus::Unverified,
            sourceUrl: $url,
        ), $this->extractCodesFromLinkTitles($html));
    }
}
