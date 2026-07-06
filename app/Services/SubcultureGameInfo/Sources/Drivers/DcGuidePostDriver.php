<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\GuideSource;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Contracts\GuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData;
use Carbon\Carbon;

/**
 * 디씨인사이드 마이너 갤러리 개념글(exception_mode=recommend) 목록 수집.
 * 갤러리 매핑은 리딤코드와 동일한 drivers.dc.galleries 를 재사용한다.
 */
class DcGuidePostDriver implements GuidePostDriver
{
    use FetchesWebContent;

    public function source(): GuideSource
    {
        return GuideSource::Dc;
    }

    public function fetchPosts(string $gameSlug): array
    {
        $cfg = config('subculture-game-info.drivers.dc');
        $gallery = $cfg['galleries'][$gameSlug] ?? null;
        if ($gallery === null) {
            return [];
        }

        $html = $this->getHtml($cfg['base'], ['id' => $gallery, 'exception_mode' => 'recommend']);
        if ($html === null) {
            return [];
        }

        $xp = $this->xpath($html);
        $posts = [];
        foreach ($xp->query("//tr[contains(concat(' ', normalize-space(@class), ' '), ' ub-content ')]") ?: [] as $tr) {
            // 글번호가 숫자가 아니면(공지/설문/광고 행) 스킵
            $numNode = $xp->query(".//td[contains(@class, 'gall_num')]", $tr)->item(0);
            $externalId = $numNode instanceof \DOMElement ? trim($numNode->textContent) : '';
            if (! preg_match('/^\d+$/', $externalId)) {
                continue;
            }

            $a = $xp->query(".//td[contains(@class, 'gall_tit')]//a[not(contains(@href, 'addc.')) and not(starts-with(@href, 'javascript'))]", $tr)->item(0);
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $title = trim(preg_replace('/\s+/u', ' ', $a->textContent) ?? '');
            $href = $a->getAttribute('href');
            if ($title === '' || $href === '') {
                continue;
            }
            $url = str_starts_with($href, 'http') ? $href : 'https://gall.dcinside.com'.$href;

            $postedAt = null;
            $dateNode = $xp->query(".//td[contains(@class, 'gall_date')]", $tr)->item(0);
            if ($dateNode instanceof \DOMElement) {
                try {
                    $postedAt = Carbon::parse(
                        $dateNode->getAttribute('title') ?: $dateNode->textContent,
                        config('app.timezone', 'Asia/Seoul'),
                    );
                } catch (\Throwable) {
                    $postedAt = null;
                }
            }

            $countNode = $xp->query(".//td[contains(@class, 'gall_count')]", $tr)->item(0);
            $views = $countNode instanceof \DOMElement ? (int) preg_replace('/\D/', '', $countNode->textContent) : 0;

            $posts[] = new GuidePostData(
                externalId: $externalId,
                title: $title,
                url: $url,
                postedAt: $postedAt,
                views: $views,
            );
        }

        return $posts;
    }
}
