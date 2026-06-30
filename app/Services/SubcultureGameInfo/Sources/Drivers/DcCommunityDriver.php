<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\Contracts\CodeSearchDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use App\Services\SubcultureGameInfo\Sources\DTO\CommunitySearchHit;

/**
 * 디씨인사이드 마이너 갤러리 — 보조 신호(community).
 * 코드 키워드가 든 글 제목에서만 코드 토큰을 미검증으로 수집한다.
 * 또한 특정 코드를 갤러리에서 직접 '검색'해 교차검증한다(CodeSearchDriver).
 */
class DcCommunityDriver extends AbstractSourceDriver implements CodeSearchDriver
{
    public function driverKey(): string
    {
        return 'dc';
    }

    public function isCommunity(): bool
    {
        return true;
    }

    /**
     * 코드를 이 게임 디씨 갤러리에서 검색해 (제목, 작성일) 목록을 만든 뒤 공통 평가기로 넘긴다.
     * 디씨 마크업(tr.ub-content > td.gall_tit)은 아카와 달라 파싱은 여기서, 판정은 evaluateSearchRows()가 한다.
     */
    public function searchCode(string $gameSlug, string $code): ?CommunitySearchHit
    {
        $cfg = config('subculture-game-info.drivers.dc');
        $gallery = $cfg['galleries'][$gameSlug] ?? null;
        if ($gallery === null) {
            return null;
        }

        // 제목+본문 검색(s_type=search_subject_memo). 코드는 영숫자라 인코딩 안전.
        $html = $this->getHtml($cfg['base'], [
            'id' => $gallery,
            's_type' => 'search_subject_memo',
            's_keyword' => $code,
        ]);
        if ($html === null) {
            return null;
        }

        $url = $cfg['base'].'?id='.$gallery.'&s_type=search_subject_memo&s_keyword='.rawurlencode($code);

        $xp = $this->xpath($html);
        $rows = [];
        foreach ($xp->query("//tr[contains(concat(' ', normalize-space(@class), ' '), ' ub-content ')]") ?: [] as $tr) {
            // 광고/공지/이벤트 링크(addc·javascript)는 제외하고 실제 글 제목만.
            $a = $xp->query(".//td[contains(@class, 'gall_tit')]//a[not(contains(@href, 'addc.')) and not(starts-with(@href, 'javascript'))]", $tr)->item(0);
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $title = trim(preg_replace('/\s+/u', ' ', $a->textContent) ?? '');
            $dateNode = $xp->query(".//td[contains(@class, 'gall_date')]", $tr)->item(0);
            $date = $dateNode instanceof \DOMElement
                ? $this->parseExpiry($dateNode->getAttribute('title') ?: $dateNode->textContent)
                : null;
            $rows[] = [$title, $date];
        }

        return $this->evaluateSearchRows($rows, $code, 'dc-search', $url);
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
