<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\Contracts\CodeSearchDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use App\Services\SubcultureGameInfo\Sources\DTO\CommunitySearchHit;
use Carbon\Carbon;

/**
 * 아카라이브 채널 — 보조 신호(community).
 * 실제 브라우저 UA면 정적 HTTP로 채널 글 목록 접근 가능(이전 403은 봇 UA 탓).
 * 코드 키워드가 든 글 제목에서만 코드 토큰을 미검증으로 수집한다.
 * 또한 특정 코드를 채널에서 직접 '검색'해 교차검증한다(CodeSearchDriver).
 */
class ArcaCommunityDriver extends AbstractSourceDriver implements CodeSearchDriver
{
    public function driverKey(): string
    {
        return 'arca';
    }

    public function isCommunity(): bool
    {
        return true;
    }

    public function searchCode(string $gameSlug, string $code): ?CommunitySearchHit
    {
        $cfg = config('subculture-game-info.drivers.arca');
        $channel = $cfg['channels'][$gameSlug] ?? null;
        if ($channel === null) {
            return null;
        }

        $base = rtrim($cfg['base'], '/').'/'.$channel;
        $html = $this->getHtml($base, ['target' => 'all', 'keyword' => $code]);
        if ($html === null) {
            return null;
        }

        $url = $base.'?target=all&keyword='.rawurlencode($code);

        $xp = $this->xpath($html);
        $rows = [];
        // 글 행(a.vrow.column)에서 제목(.col-title .title)과 작성일(time[datetime])을 뽑는다.
        // (a.title 은 채널 헤더라 글 제목이 아니다.)
        $vrows = $xp->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' vrow ') and contains(concat(' ', normalize-space(@class), ' '), ' column ')]");
        foreach ($vrows ?: [] as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $titleNode = $xp->query(".//*[contains(@class, 'col-title')]//span[contains(concat(' ', normalize-space(@class), ' '), ' title ')]", $a)->item(0);
            $title = $titleNode instanceof \DOMElement
                ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent) ?? '')
                : '';
            if ($title === '') {
                continue;
            }
            $date = null;
            $timeNode = $xp->query('.//time[@datetime]', $a)->item(0);
            if ($timeNode instanceof \DOMElement) {
                try {
                    $date = Carbon::parse($timeNode->getAttribute('datetime'));
                } catch (\Throwable) {
                    $date = null;
                }
            }
            $rows[] = [$title, $date];
        }

        return $this->evaluateSearchRows($rows, $code, 'arca-search', $url);
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
