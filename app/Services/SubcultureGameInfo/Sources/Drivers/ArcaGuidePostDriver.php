<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\GuideSource;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Contracts\GuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData;
use Carbon\Carbon;

/**
 * 아카라이브 채널 추천글(mode=best) 목록 수집.
 * 채널 매핑은 리딤코드와 동일한 drivers.arca.channels 를 재사용한다.
 */
class ArcaGuidePostDriver implements GuidePostDriver
{
    use FetchesWebContent;

    public function source(): GuideSource
    {
        return GuideSource::Arca;
    }

    public function fetchPosts(string $gameSlug): array
    {
        $cfg = config('subculture-game-info.drivers.arca');
        $channel = $cfg['channels'][$gameSlug] ?? null;
        if ($channel === null) {
            return [];
        }

        $base = rtrim($cfg['base'], '/').'/'.$channel;
        $html = $this->getHtml($base, ['mode' => 'best']);
        if ($html === null) {
            return [];
        }

        $xp = $this->xpath($html);
        $posts = [];
        $vrows = $xp->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' vrow ') and contains(concat(' ', normalize-space(@class), ' '), ' column ')]");
        foreach ($vrows ?: [] as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }

            // 글번호: href(/b/{channel}/{글번호}?...) 에서 추출 — 공지 등 비정형 행은 스킵
            $href = $a->getAttribute('href');
            if (! preg_match('#/b/[^/]+/(\d+)#', $href, $m)) {
                continue;
            }
            $externalId = $m[1];

            $titleNode = $xp->query(".//*[contains(@class, 'col-title')]//span[contains(concat(' ', normalize-space(@class), ' '), ' title ')]", $a)->item(0);
            $title = $titleNode instanceof \DOMElement
                ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent) ?? '')
                : '';
            if ($title === '') {
                continue;
            }

            $postedAt = null;
            $timeNode = $xp->query('.//time[@datetime]', $a)->item(0);
            if ($timeNode instanceof \DOMElement) {
                try {
                    $postedAt = Carbon::parse($timeNode->getAttribute('datetime'));
                } catch (\Throwable) {
                    $postedAt = null;
                }
            }

            $viewNode = $xp->query(".//*[contains(@class, 'col-view')]", $a)->item(0);
            $views = $viewNode instanceof \DOMElement ? (int) preg_replace('/\D/', '', $viewNode->textContent) : 0;

            $posts[] = new GuidePostData(
                externalId: $externalId,
                title: $title,
                url: 'https://arca.live'.parse_url($href, PHP_URL_PATH),
                postedAt: $postedAt,
                views: $views,
            );
        }

        return $posts;
    }
}
