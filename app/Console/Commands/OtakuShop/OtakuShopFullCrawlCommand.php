<?php

namespace App\Console\Commands\OtakuShop;

use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\ShopCrawlRunner;
use Illuminate\Console\Command;

/**
 * 전량 크롤 (최초 1회 적재용).
 *
 * 사이트 메뉴에서 모든 상품 카테고리를 자동 발견해 끝 페이지까지 수집한다.
 * 대상 서버 차단 방지를 위해 일반 크롤보다 긴 딜레이(config: otaku-crawler.crawl.full)를 사용한다.
 * 시간이 오래 걸리므로 처음 한 번만 돌리고, 이후 갱신은 otaku-shop:crawl(증분)에 맡긴다.
 */
class OtakuShopFullCrawlCommand extends Command
{
    protected $signature = 'otaku-shop:crawl-full
                            {--yes : 확인 프롬프트 없이 바로 실행(스케줄/CI용)}';

    protected $description = '오타쿠샵 3사 전량 크롤 (최초 1회, 모든 카테고리·끝 페이지·차단 방지 딜레이)';

    public function handle(ShopCrawlRunner $runner, CrawlSyncService $syncService): int
    {
        $reqMs = (int) config('otaku-crawler.crawl.full.delay_ms_between_requests', 3000);
        $shopMs = (int) config('otaku-crawler.crawl.full.delay_ms_between_shops', 8000);
        $this->warn('전량 크롤은 모든 카테고리를 끝 페이지까지 수집하므로 수십 분 이상 걸릴 수 있습니다.');
        $this->line("  요청 간 딜레이 {$reqMs}ms / 샵 간 딜레이 {$shopMs}ms (차단 방지)");

        if (! $this->option('yes') && ! $this->confirm('계속할까요?', true)) {
            $this->info('취소되었습니다.');

            return self::SUCCESS;
        }

        $this->info('1. 샵/카테고리 동기화...');
        $syncService->syncShops();
        $syncService->syncCategories();

        // 샵 하나가 끝날 때마다 바로 DB에 저장한다. 뒤 샵이 실패해도 앞 샵 데이터는 안전하다.
        $this->info('2. 전량 크롤 + 샵별 즉시 저장 (카테고리 자동 발견 + 끝 페이지까지)...');
        $stats = ['products_created' => 0, 'products_matched' => 0, 'offers_created' => 0, 'offers_updated' => 0];
        $total = $runner->run(
            full: true,
            onLine: fn (string $line) => $this->line('  '.$line),
            onShop: function (string $name, array $items) use ($syncService, &$stats): void {
                $s = $syncService->syncProductsAndOffers($items, incremental: false);
                foreach ($stats as $key => $value) {
                    $stats[$key] += $s[$key];
                }
                $this->line("    ↳ [{$name}] 저장: 신규상품 {$s['products_created']} · 매칭 {$s['products_matched']} · 신규오퍼 {$s['offers_created']} · 갱신오퍼 {$s['offers_updated']}");
            }
        );
        $this->info('수집 상품 수: '.$total);

        if ($total === 0) {
            $this->warn('크롤된 상품이 없습니다. Selenium 드라이버(Chrome) 실행 여부를 확인하세요.');

            return self::FAILURE;
        }

        $this->info('3. 누적 저장 결과:');
        $this->table(
            ['항목', '건수'],
            [
                ['신규 상품', $stats['products_created']],
                ['매칭 상품', $stats['products_matched']],
                ['신규 오퍼', $stats['offers_created']],
                ['업데이트 오퍼', $stats['offers_updated']],
            ]
        );

        $this->info('완료. 이후 갱신은 otaku-shop:crawl --incremental (매일 스케줄)이 담당합니다.');

        return self::SUCCESS;
    }
}
