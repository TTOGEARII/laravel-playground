<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuExchangeRate;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use App\Services\OtakuShop\Crawler\GlobalShops\AmiamiCrawler;
use App\Services\OtakuShop\Crawler\GlobalShops\OtakuSidecarRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * 해외관(아미아미) — 사이드카는 CI 에서 실행 불가라 러너를 목으로 대체하고
 * PHP 레이어(DTO 매핑·JAN 매칭·통화 저장·최저가 환산·커맨드)만 검증한다.
 */
class AmiamiGlobalCrawlTest extends TestCase
{
    use RefreshDatabase;

    /** 사이드카 러너를 목으로 바꾼다. 페이로드를 여러 개 주면 호출 순서대로 반환한다. */
    private function mockRunner(?array ...$payloads): void
    {
        $runner = Mockery::mock(OtakuSidecarRunner::class);
        $runner->shouldReceive('run')->andReturn(...$payloads);
        $this->app->instance(OtakuSidecarRunner::class, $runner);
    }

    /** 사이드카 stdout 계약 페이로드. */
    private function sidecarPayload(array $items): array
    {
        return ['shop' => 'amiami', 'source' => 'amiami-api', 'items' => $items];
    }

    /** 사이드카 아이템 1건(스펙 실측 스키마). 제목은 IP 별칭·13자리 숫자가 없는 순수 영문. */
    private function amiamiItem(array $overrides = []): array
    {
        return array_merge([
            'gcode' => 'FIGURE-186786',
            'title' => 'Dorothy 1/7 Scale Figure',
            'price_jpy' => 24800,
            'jancode' => '4573102668394',
            'image_url' => 'https://img.amiami.com/images/product/thumb300/261/FIGURE-186786.jpg',
            'release_date' => '2027-03-31',
            'available' => true,
            'preorder' => true,
        ], $overrides);
    }

    /** 국내 샵 DTO (CrawlSyncServiceTest 컨벤션과 동일). */
    private function krDto(string $shop, string $extId, string $title, float $price, ?string $makerCode = null, string $categoryCode = 'figure'): CrawledProductDto
    {
        return new CrawledProductDto(
            shopCode: $shop,
            externalId: $extId,
            title: $title,
            subtitle: null,
            brandLabel: null,
            price: $price,
            productUrl: 'https://example.com/'.$extId,
            categoryCode: $categoryCode,
            imageUrl: 'https://example.com/'.$extId.'.jpg',
            makerCode: $makerCode,
        );
    }

    /** 샵(config 전체, amiami 포함)/카테고리/IP 사전을 시드한다. */
    private function seedRefs(CrawlSyncService $service): void
    {
        $service->syncShops();
        $service->syncCategories();
        $service->syncIps();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DTO 매핑 (AmiamiCrawler)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_amiami_items_map_to_jpy_figure_dtos_with_jan_maker_code(): void
    {
        $this->mockRunner($this->sidecarPayload([$this->amiamiItem()]));
        $dtos = $this->app->make(AmiamiCrawler::class)->crawlProducts();

        $this->assertCount(1, $dtos);
        $dto = $dtos[0];
        $this->assertSame('amiami', $dto->shopCode);
        $this->assertSame('FIGURE-186786', $dto->externalId);
        $this->assertSame('Dorothy 1/7 Scale Figure', $dto->title);
        $this->assertSame(24800.0, $dto->price);
        $this->assertSame('JPY', $dto->currency, '오퍼는 JPY 원가로 저장(환산은 표시층 몫)');
        $this->assertSame('jan_4573102668394', $dto->makerCode, 'JAN 은 기존 jan_ 접두 컨벤션');
        $this->assertSame('figure', $dto->categoryCode);
        $this->assertSame('2027-03-31', $dto->releaseDate);
        $this->assertSame('https://www.amiami.com/eng/detail?gcode=FIGURE-186786', $dto->productUrl);
        $this->assertTrue($dto->available);
    }

    public function test_amiami_skips_used_and_janless_and_invalid_items(): void
    {
        $this->mockRunner($this->sidecarPayload([
            $this->amiamiItem(),
            $this->amiamiItem(['gcode' => 'FIGURE-100001-R', 'jancode' => '4573102660001']), // 중고(-R)
            $this->amiamiItem(['gcode' => 'FIGURE-100002', 'jancode' => null]),              // JAN 없음
            $this->amiamiItem(['gcode' => 'FIGURE-100003', 'jancode' => '123']),             // JAN 자릿수 미달
            $this->amiamiItem(['gcode' => 'FIGURE-100004', 'price_jpy' => 0]),               // 가격 없음
        ]));

        $dtos = $this->app->make(AmiamiCrawler::class)->crawlProducts();

        $this->assertCount(1, $dtos, '신품 + JAN 보유 + 가격 유효 상품만 수집돼야 함');
        $this->assertSame('FIGURE-186786', $dtos[0]->externalId);
    }

    public function test_amiami_release_date_parses_iso_and_english_month(): void
    {
        $this->mockRunner($this->sidecarPayload([
            $this->amiamiItem(),                                                                      // ISO 전체 날짜
            $this->amiamiItem(['gcode' => 'F-2', 'jancode' => '4573102660002', 'release_date' => 'late Jan-2027']),
            $this->amiamiItem(['gcode' => 'F-3', 'jancode' => '4573102660003', 'release_date' => '미정?']),
        ]));

        $dtos = $this->app->make(AmiamiCrawler::class)->crawlProducts();

        $this->assertSame('2027-03-31', $dtos[0]->releaseDate);
        $this->assertSame('2027-01-01', $dtos[1]->releaseDate, '영문 월 표기는 월 첫날로 폴백');
        $this->assertNull($dtos[2]->releaseDate, '해석 불가 표기는 null(방어)');
    }

    public function test_amiami_sidecar_failure_falls_back_to_empty(): void
    {
        $this->mockRunner(null);

        $this->assertSame([], $this->app->make(AmiamiCrawler::class)->crawlProducts());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 동기화: 통화 저장 + JAN 교차 매칭 (국내 상품에 JPY 오퍼)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sync_stores_jpy_currency_and_global_shop_attributes(): void
    {
        $this->mockRunner($this->sidecarPayload([$this->amiamiItem()]));
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        $service->syncProductsAndOffers($this->app->make(AmiamiCrawler::class)->crawlProducts(), incremental: true);

        $shop = OtakuShop::where('ok_shop_code', 'amiami')->first();
        $this->assertSame('global', $shop->ok_shop_region, 'config 샵 정의의 지역이 반영돼야 함');
        $this->assertSame('JPY', $shop->ok_shop_currency);

        $offer = OtakuOffer::first();
        $this->assertSame('JPY', $offer->ok_offer_currency);
        $this->assertSame('24800.00', $offer->ok_offer_price, 'JPY 원가 그대로(환산 저장 금지)');
    }

    public function test_jan_match_attaches_jpy_offer_to_existing_domestic_product_across_runs(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 1차(국내 크롤 회차): IP 표기가 있는 국내 상품 — 번들 키에 IP 접미가 붙는다.
        $service->syncProductsAndOffers([
            $this->krDto('dokidokigoods', 'A1', '[승리의 여신 니케] 도로시 1/7 스케일 피규어', 250000, makerCode: 'jan_4573102668394'),
        ], incremental: false);
        $domestic = OtakuProduct::first();
        $this->assertNotNull($domestic->ok_product_ip_id, '국내 제목에서 IP 가 분류돼야 전제가 성립');

        // 2차(해외 크롤 회차): 영문 제목이라 IP 를 못 뽑아도 같은 JAN 이면 국내 상품에 JPY 오퍼가 붙는다.
        $this->mockRunner($this->sidecarPayload([$this->amiamiItem(['price_jpy' => 19800])]));
        $service->syncProductsAndOffers($this->app->make(AmiamiCrawler::class)->crawlProducts(), incremental: true);

        $this->assertSame(1, OtakuProduct::count(), '같은 JAN 은 런이 달라도 한 상품으로 묶여야 함(교차 가격비교)');
        $this->assertSame(2, OtakuOffer::count());

        $jpyOffer = OtakuOffer::where('ok_offer_currency', 'JPY')->first();
        $this->assertSame((int) $domestic->ok_product_id, (int) $jpyOffer->ok_offer_product_id);
        // IP 를 모르는(영문) 표기가 국내 상품의 IP 접미 키를 벗겨내면 크롤이 번갈아 돌 때마다
        // 키가 왕복하므로, IP 를 아는 쪽 키를 유지해야 한다(키 드리프트 방지).
        $this->assertSame($domestic->ok_product_code, $domestic->fresh()->ok_product_code);
    }

    public function test_different_jan_creates_separate_product(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        $service->syncProductsAndOffers([
            $this->krDto('dokidokigoods', 'A1', '[승리의 여신 니케] 도로시 1/7 스케일 피규어', 250000, makerCode: 'jan_4573102668394'),
        ], incremental: false);

        // JAN 이 다르면(다른 실물) 영문 제목이 비슷해도 절대 묶이지 않아야 한다(미병합 > 과병합).
        $this->mockRunner($this->sidecarPayload([$this->amiamiItem(['jancode' => '4573102669999'])]));
        $service->syncProductsAndOffers($this->app->make(AmiamiCrawler::class)->crawlProducts(), incremental: true);

        $this->assertSame(2, OtakuProduct::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 최저가 플래그: KRW 환산 비교 (원시 숫자 비교 금지)
    // ─────────────────────────────────────────────────────────────────────────

    /** 같은 JAN 으로 국내 KRW + 아미아미 JPY 오퍼가 붙은 상품을 만든다. */
    private function seedCrossCurrencyProduct(CrawlSyncService $service, float $krwPrice, float $jpyPrice): void
    {
        $this->seedRefs($service);
        $service->syncProductsAndOffers([
            $this->krDto('dokidokigoods', 'A1', '[승리의 여신 니케] 도로시 1/7 스케일 피규어', $krwPrice, makerCode: 'jan_4573102668394'),
        ], incremental: false);
        $this->mockRunner($this->sidecarPayload([$this->amiamiItem(['price_jpy' => $jpyPrice])]));
        $service->syncProductsAndOffers($this->app->make(AmiamiCrawler::class)->crawlProducts(), incremental: true);
    }

    public function test_lowest_flag_keeps_krw_offer_when_jpy_converted_is_more_expensive(): void
    {
        OtakuExchangeRate::create(['ok_rate_currency' => 'JPY', 'ok_rate_krw' => 9.08]);
        $service = $this->app->make(CrawlSyncService::class);

        // ¥20,000 → ₩181,600 > ₩150,000. 원시 숫자 비교(20000 < 150000)라면 JPY 가 가로챘을 케이스.
        $this->seedCrossCurrencyProduct($service, 150000, 20000);

        $lowest = OtakuOffer::where('ok_offer_lowest_flg', true)->get();
        $this->assertCount(1, $lowest);
        $this->assertSame('KRW', $lowest->first()->ok_offer_currency, '환산가 기준 국내 오퍼가 최저가여야 함');
    }

    public function test_lowest_flag_marks_jpy_offer_when_converted_is_cheaper(): void
    {
        OtakuExchangeRate::create(['ok_rate_currency' => 'JPY', 'ok_rate_krw' => 9.08]);
        $service = $this->app->make(CrawlSyncService::class);

        // ¥10,000 → ₩90,800 < ₩150,000 — 해외가 진짜 싸면 JPY 오퍼가 최저가.
        $this->seedCrossCurrencyProduct($service, 150000, 10000);

        $lowest = OtakuOffer::where('ok_offer_lowest_flg', true)->get();
        $this->assertCount(1, $lowest);
        $this->assertSame('JPY', $lowest->first()->ok_offer_currency);
    }

    public function test_lowest_flag_excludes_offers_of_currency_without_rate(): void
    {
        // 환율 미수집 상태: JPY 는 환산 불가 → 비교에서 제외되고 KRW 오퍼가 최저가.
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedCrossCurrencyProduct($service, 150000, 10000);

        $lowest = OtakuOffer::where('ok_offer_lowest_flg', true)->get();
        $this->assertCount(1, $lowest);
        $this->assertSame('KRW', $lowest->first()->ok_offer_currency, '환율 없인 JPY 를 최저가로 판단하면 안 됨');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 커맨드 otaku-shop:crawl-global
    // ─────────────────────────────────────────────────────────────────────────

    public function test_crawl_global_command_syncs_and_marks_unseen_amiami_offers_soldout(): void
    {
        // 1차 커맨드: 아미아미 2건 적재. 2차 커맨드: 1건만 재수집(다른 1건은 예약 종료로 사라짐).
        $this->mockRunner(
            $this->sidecarPayload([
                $this->amiamiItem(),
                $this->amiamiItem(['gcode' => 'FIGURE-200001', 'jancode' => '4573102660002', 'title' => 'Rapunzel 1/7 Scale Figure']),
            ]),
            $this->sidecarPayload([$this->amiamiItem()]),
        );

        $this->artisan('otaku-shop:crawl-global')->assertSuccessful();
        $this->assertSame(2, OtakuOffer::where('ok_offer_available_flg', true)->count());

        // 국내 샵 오퍼(과거 수집분)를 심어 해외 품절 처리가 국내를 건드리지 않는지 함께 검증.
        $krShopId = OtakuShop::where('ok_shop_code', 'dokidokigoods')->first()->ok_shop_id;
        $krProduct = OtakuProduct::create(['ok_product_code' => 'p_kr', 'ok_product_title' => '국내 상품', 'ok_product_active_flg' => true]);
        OtakuOffer::create([
            'ok_offer_product_id' => $krProduct->ok_product_id,
            'ok_offer_shop_id' => $krShopId,
            'ok_offer_external_id' => 'KR1',
            'ok_offer_currency' => 'KRW',
            'ok_offer_price' => 50000,
            'ok_offer_available_flg' => true,
            'ok_offer_collected_dt' => Carbon::now()->subDay(),
        ]);
        OtakuOffer::where('ok_offer_currency', 'JPY')->update(['ok_offer_collected_dt' => Carbon::now()->subDay()]);

        $this->artisan('otaku-shop:crawl-global')->assertSuccessful();

        $this->assertFalse(
            (bool) OtakuOffer::where('ok_offer_external_id', 'FIGURE-200001')->first()->ok_offer_available_flg,
            '이번 회차에 안 보인 아미아미 오퍼는 예약 종료(품절) 처리'
        );
        $this->assertTrue(
            (bool) OtakuOffer::where('ok_offer_external_id', 'FIGURE-186786')->first()->ok_offer_available_flg,
            '재수집된 오퍼는 구매 가능 유지'
        );
        $this->assertTrue(
            (bool) OtakuOffer::where('ok_offer_external_id', 'KR1')->first()->ok_offer_available_flg,
            '해외 품절 처리는 국내 샵 오퍼를 건드리면 안 됨'
        );
    }

    public function test_crawl_global_command_fails_without_soldout_processing_when_sidecar_dies(): void
    {
        // 기존 아미아미 오퍼(과거 수집분)를 심어두고 사이드카가 죽은 회차를 재현.
        $this->mockRunner($this->sidecarPayload([$this->amiamiItem()]), null);
        $this->artisan('otaku-shop:crawl-global')->assertSuccessful();
        OtakuOffer::query()->update(['ok_offer_collected_dt' => Carbon::now()->subDay()]);

        $this->artisan('otaku-shop:crawl-global')->assertFailed();

        $this->assertTrue(
            (bool) OtakuOffer::first()->ok_offer_available_flg,
            '크롤 실패(0건) 회차에 전 오퍼 품절 오인이 있으면 안 됨'
        );
    }

    public function test_crawl_global_command_rejects_unknown_shop(): void
    {
        $this->artisan('otaku-shop:crawl-global', ['--shop' => 'nonexistent'])->assertFailed();
    }
}
