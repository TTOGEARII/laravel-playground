<?php

namespace App\Console\Commands\OtakuShop;

use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\GlobalShops\AmiamiCrawler;
use App\Services\OtakuShop\RestockAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 해외관 크롤: Playwright 사이드카로 해외 샵(현재 아미아미)을 수집해 동기화한다.
 *
 * 오퍼는 현지 통화(JPY) 원가로 저장하고 환산은 표시층 몫이다. 매칭은 JAN 바코드만
 * 태우므로(영문 제목이라 정규화 매칭 신뢰 불가) 국내 상품과 JAN 이 같으면 그 상품에
 * JPY 오퍼가 붙는다(교차 가격비교). 스케줄 등록은 운영 검증 후 별도로 붙인다.
 */
class OtakuShopGlobalCrawlCommand extends Command
{
    protected $signature = 'otaku-shop:crawl-global
                            {--shop=amiami : 크롤할 해외 샵 코드}';

    protected $description = '오타쿠샵 해외관 크롤 (아미아미 — 예약 상품, JAN 매칭, JPY 오퍼)';

    /** 해외 샵 코드 → 크롤러 구현체. 새 해외 샵 추가 시 여기에 등록한다. */
    private const CRAWLERS = [
        AmiamiCrawler::SHOP_CODE => AmiamiCrawler::class,
    ];

    public function handle(CrawlSyncService $syncService, RestockAlertService $restockAlerts): int
    {
        $shopCode = strtolower(trim((string) $this->option('shop')));
        $crawlerClass = self::CRAWLERS[$shopCode] ?? null;
        if ($crawlerClass === null) {
            $this->error("지원하지 않는 해외 샵 코드입니다: {$shopCode} (지원: ".implode(', ', array_keys(self::CRAWLERS)).')');

            return self::FAILURE;
        }

        // 샵/카테고리/IP 는 멱등 보장(코드→ID 매핑에 필수). 신규 amiami 샵 행도 여기서 생긴다.
        $this->info('1. 샵/카테고리/IP 동기화...');
        $syncService->syncShops();
        $syncService->syncCategories();
        $syncService->syncIps();

        $this->info("2. 해외 샵 크롤: {$shopCode} (Playwright 사이드카)...");
        $runStartedAt = Carbon::now();
        /** @var \App\Services\OtakuShop\Crawler\Contracts\ShopCrawlerInterface $crawler */
        $crawler = $this->laravel->make($crawlerClass);
        $items = $crawler->crawlProducts();
        $this->info('수집 상품 수: '.count($items));

        if ($items === []) {
            // 사이드카 실패/0건이면 품절 처리를 하지 않는다 — "크롤 실패 = 전 오퍼 품절" 오인 방지.
            $this->warn('크롤된 상품이 없습니다. 사이드카(Node/Playwright) 로그를 확인하세요.');

            return self::FAILURE;
        }

        $this->info('3. 상품·오퍼 동기화 (JAN 매칭, JPY 원가 저장)...');
        $stats = $syncService->syncProductsAndOffers($items, incremental: true);

        // 예약 목록 전체를 도는 수집이므로, 이번에 못 본 이 샵 오퍼는 예약 종료(품절)로 본다.
        // markUnseenOffersUnavailable 은 넘긴 샵 코드의 오퍼만 건드려 국내 샵에는 영향이 없다.
        $this->info('4. 사라진(미수집) 오퍼 품절 처리...');
        $soldOut = $syncService->markUnseenOffersUnavailable([$shopCode], $runStartedAt);
        $this->line("    ↳ 품절 전환된 오퍼: {$soldOut}건");

        // 품절→구매가능으로 바뀐 찜 상품이 있으면 웹푸시(해외 오퍼로 다시 구매 가능해진 경우 포함).
        $restocked = $syncService->pullRestockedProductIds();
        if ($restocked !== []) {
            $r = $restockAlerts->notify($restocked);
            $this->info("재입고 알림: 찜 상품 {$r['products']} · 유저 {$r['users']} · 발송 {$r['sent']}");
        }

        $this->info('5. 저장 결과:');
        $this->table(
            ['항목', '건수'],
            [
                ['신규 상품', $stats['products_created']],
                ['매칭 상품', $stats['products_matched']],
                ['신규 오퍼', $stats['offers_created']],
                ['업데이트 오퍼', $stats['offers_updated']],
                ['품절 전환 오퍼', $soldOut],
            ]
        );

        return self::SUCCESS;
    }
}
