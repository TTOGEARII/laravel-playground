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

    /**
     * 코드를 이 게임 아카 채널에서 검색해 (제목, 작성일) 목록을 만든 뒤 공통 평가기로 넘긴다.
     * 아카 마크업(a.vrow.column > .col-title .title)은 디씨와 달라 파싱은 여기서, 판정은 evaluateSearchRows()가 한다.
     */
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

        return $this->evaluateSearchRows($this->parseListRows($html), $code, 'arca-search', $url);
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.arca');
        $channel = $cfg['channels'][$gameSlug] ?? null;
        if ($channel === null) {
            return [];
        }

        $base = rtrim($cfg['base'], '/').'/'.$channel;
        $region = $this->regionFor($gameSlug);
        $category = $cfg['categories'][$gameSlug] ?? null;

        // 쿠폰 카테고리가 지정된 게임(예: 니케)은 해당 카테고리의 '최근 N일' 글 제목에서 코드를 수집한다.
        // 1차 소스(공식 등)에 없던 코드는 신규로 추가되고, 이미 있으면 교차검증으로 신뢰도만 오른다.
        if ($category !== null) {
            $days = (int) ($cfg['recent_days'] ?? 7);
            $html = $this->getHtml($base, ['category' => $category]);
            if ($html === null) {
                return [];
            }
            $url = $base.'?category='.rawurlencode($category);
            $cutoff = Carbon::now()->subDays($days);

            $out = [];
            foreach ($this->parseListRows($html) as [$title, $date]) {
                // 최근 N일 내 글만(작성일이 없으면 보수적으로 제외).
                if ($date === null || $date->lt($cutoff)) {
                    continue;
                }
                foreach ($this->extractCodeTokensFromText($title) as $code) {
                    $out[strtoupper($code)] = new CollectedCodeDto(
                        gameSlug: $gameSlug,
                        code: $code,
                        sourceType: SourceType::Community,
                        source: $this->driverKey(),
                        region: $region,
                        status: CodeStatus::Unverified,
                        sourceUrl: $url,
                    );
                }
            }

            return array_values($out);
        }

        // 그 외 게임: 기존 방식(코드 키워드가 든 글 제목에서 토큰 추출).
        $html = $this->getHtml($base);
        if ($html === null) {
            return [];
        }

        return array_map(fn ($code) => new CollectedCodeDto(
            gameSlug: $gameSlug,
            code: $code,
            sourceType: SourceType::Community,
            source: $this->driverKey(),
            region: $region,
            status: CodeStatus::Unverified,
            sourceUrl: $base,
        ), $this->extractCodesFromLinkTitles($html));
    }

    /**
     * 아카 채널 글 목록 HTML에서 (제목, 작성일) 행을 파싱한다. searchCode/collect 공용.
     * 글 행(a.vrow.column)에서 제목(.col-title .title)과 작성일(time[datetime])을 뽑는다.
     * (a.title 은 채널 헤더라 글 제목이 아니다.)
     *
     * @return array<int, array{0: string, 1: ?Carbon}>
     */
    private function parseListRows(string $html): array
    {
        $xp = $this->xpath($html);
        $rows = [];
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

        return $rows;
    }
}
