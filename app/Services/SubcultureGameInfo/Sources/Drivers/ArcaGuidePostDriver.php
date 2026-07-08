<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\GuideSource;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Contracts\GuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData;
use Carbon\Carbon;

/**
 * 아카라이브 채널 공략글 목록 수집 — 추천글(mode=best) + 공략 카테고리(guide_categories).
 * 추천글만으로는 팬아트·유머가 대부분이라, 채널별 공략 전용 카테고리(블아 '택틱',
 * 니케 '솔로레이드' 등)를 함께 긁어야 대체 캐릭터 추출 재료가 확보된다.
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

        // 공략 카테고리 + 추천글을 합쳐 글번호 기준으로 중복 제거.
        // 수집기가 소스당 상한(max_posts_per_source)으로 자르므로,
        // 공략 밀도가 높은 카테고리를 앞에 둬야 잘려도 공략글이 남는다.
        $queries = [];
        foreach ($cfg['guide_categories'][$gameSlug] ?? [] as $category) {
            $queries[] = ['category' => $category];
        }
        $queries[] = ['mode' => 'best'];

        // 쿼리(카테고리)별로 추천·조회수 상위를 앞세워 합친다 — 수집 상한(max_posts_per_source)에
        // 잘려도 인기 공략이 남는다. 쿼리 간 순서(공략 카테고리 → 추천글)는 유지해
        // 팬아트 위주의 추천글이 상한을 독식하지 않게 한다.
        $posts = [];
        foreach ($queries as $i => $query) {
            if ($i > 0) {
                usleep(1_000_000); // 목록 페이지 다연속 요청으로 차단당하지 않게 1초 간격
            }
            foreach ($this->sortByPopularity($this->fetchList($base, $query)) as $post) {
                $posts[$post->externalId] ??= $post;
            }
        }

        return array_values($posts);
    }

    /**
     * 채널 제목 검색(target=title). 검색 결과 마크업이 일반 목록과 동일해 파서를 재사용한다.
     * 검색 결과도 추천·조회수 상위 우선.
     */
    public function searchPosts(string $gameSlug, string $keyword): array
    {
        $cfg = config('subculture-game-info.drivers.arca');
        $channel = $cfg['channels'][$gameSlug] ?? null;
        if ($channel === null) {
            return [];
        }

        return $this->sortByPopularity($this->fetchList(
            rtrim($cfg['base'], '/').'/'.$channel,
            ['target' => 'title', 'keyword' => $keyword],
        ));
    }

    /**
     * 추천 수 → 조회수 순 정렬(둘 다 품질 신호지만 추천이 더 강하다).
     *
     * @param  GuidePostData[]  $posts
     * @return GuidePostData[]
     */
    private function sortByPopularity(array $posts): array
    {
        usort($posts, fn (GuidePostData $a, GuidePostData $b) => [$b->rate, $b->views] <=> [$a->rate, $a->views]);

        return $posts;
    }

    /**
     * 목록 페이지 한 장을 파싱해 글 메타를 돌려준다.
     *
     * @return GuidePostData[]
     */
    private function fetchList(string $base, array $query): array
    {
        $html = $this->getHtml($base, $query);
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

            // 글번호: href(/b/{channel}/{글번호}?...) 에서 추출 — 공지·타채널 광고 등 비정형 행은 스킵
            $href = $a->getAttribute('href');
            if (! preg_match('#^/b/[^/]+/(\d+)#', $href, $m)) {
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

            // 추천 수(col-rate) — 0이면 &nbsp; 로 비어 있다
            $rateNode = $xp->query(".//*[contains(@class, 'col-rate')]", $a)->item(0);
            $rate = $rateNode instanceof \DOMElement ? (int) preg_replace('/\D/', '', $rateNode->textContent) : 0;

            $posts[] = new GuidePostData(
                externalId: $externalId,
                title: $title,
                url: 'https://arca.live'.parse_url($href, PHP_URL_PATH),
                postedAt: $postedAt,
                views: $views,
                rate: $rate,
            );
        }

        return $posts;
    }
}
