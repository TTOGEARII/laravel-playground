<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CrawlSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedShops(): void
    {
        OtakuShop::create(['ok_shop_code' => 'dokidokigoods', 'ok_shop_name' => '도키도키굿즈', 'ok_shop_active_flg' => true]);
        OtakuShop::create(['ok_shop_code' => 'ttabbaemall', 'ok_shop_name' => '따빼몰', 'ok_shop_active_flg' => true]);
    }

    private function dto(string $shop, string $extId, string $title, float $price, bool $available = true, ?string $makerCode = null, ?string $maker = null): CrawledProductDto
    {
        return new CrawledProductDto(
            shopCode: $shop,
            externalId: $extId,
            title: $title,
            subtitle: null,
            brandLabel: null,
            price: $price,
            currency: 'KRW',
            productUrl: 'https://example.com/'.$extId,
            categoryCode: 'goods',
            imageUrl: 'https://example.com/'.$extId.'.jpg',
            available: $available,
            makerCode: $makerCode,
            maker: $maker,
        );
    }

    public function test_same_shop_variants_collapse_to_single_cheapest_offer(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 같은 상품의 일반판/특전판을 같은 샵이 올린 경우 → 상품 1, 오퍼 1(최저가)
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', '267617', '블루 아카이브 FigUnity 피규어 - 흥신소 68', 136000),
            $this->dto('dokidokigoods', '267616', '[특전] 블루 아카이브 FigUnity 피규어 - 흥신소 68', 158000),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(1, OtakuOffer::count());
        $this->assertSame('136000.00', OtakuOffer::first()->ok_offer_price);
    }

    public function test_cross_shop_same_product_compares_and_flags_lowest(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '원신 넨도로이드 푸리나', 78000),
            $this->dto('ttabbaemall', 'B1', '원신  넨도로이드 푸리나', 72000),
        ], incremental: false);

        // 동일 상품으로 묶여 상품 1 + 오퍼 2(샵별)
        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());

        // 최저가 플래그는 더 싼 따빼몰 오퍼에만.
        $lowest = OtakuOffer::where('ok_offer_lowest_flg', true)->get();
        $this->assertCount(1, $lowest);
        $this->assertSame('72000.00', $lowest->first()->ok_offer_price);
    }

    public function test_soldout_offer_is_stored_unavailable_and_excluded_from_lowest(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 따빼몰이 더 싸지만 품절이면, 최저가 플래그는 재고 있는 도키도키굿즈 오퍼로 간다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '원신 넨도로이드 푸리나', 78000, available: true),
            $this->dto('ttabbaemall', 'B1', '원신 넨도로이드 푸리나', 72000, available: false),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());

        $soldout = OtakuOffer::where('ok_offer_external_id', 'B1')->first();
        $this->assertFalse((bool) $soldout->ok_offer_available_flg);

        $lowest = OtakuOffer::where('ok_offer_lowest_flg', true)->get();
        $this->assertCount(1, $lowest);
        $this->assertSame('78000.00', $lowest->first()->ok_offer_price);
    }

    public function test_same_shop_prefers_in_stock_variant_over_cheaper_soldout(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 같은 샵의 같은 상품: 더 싼 변형이 품절이면 재고 있는 변형을 대표 오퍼로 잡는다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', '1', '원신 넨도로이드 푸리나', 70000, available: false),
            $this->dto('dokidokigoods', '2', '원신 넨도로이드 푸리나', 78000, available: true),
        ], incremental: false);

        $this->assertSame(1, OtakuOffer::count());
        $offer = OtakuOffer::first();
        $this->assertTrue((bool) $offer->ok_offer_available_flg);
        $this->assertSame('78000.00', $offer->ok_offer_price);
    }

    public function test_cross_shop_matches_by_maker_code_despite_different_titles(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 제목 표기는 달라도 넨도로이드 번호(고유값)가 같으면 동일 상품으로 묶인다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[예약] 원신 넨도로이드 2930 푸리나 폰타인의 정의', 78000),
            $this->dto('ttabbaemall', 'B1', '원신 넨도로이드 #2930 푸리나', 72000),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
        $this->assertSame('nendo_2930', OtakuProduct::first()->ok_product_maker_code);
    }

    public function test_cross_shop_matches_by_detail_barcode_and_stores_maker(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 상세 크롤로 얻은 동일 바코드(JAN)면 제목이 전혀 달라도 한 상품으로 묶이고, 제조사명이 저장된다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '니케 도로롱 신데렐라 봉제인형', 15700, makerCode: 'jan_4580828667556', maker: '굿스마일 컴퍼니(GOOD SMILE)'),
            $this->dto('ttabbaemall', 'B1', '[예약] 승리의 여신 니케 도로 시리즈 인형', 14900, makerCode: 'jan_4580828667556'),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());

        $product = OtakuProduct::first();
        $this->assertSame('jan_4580828667556', $product->ok_product_maker_code);
        $this->assertSame('굿스마일 컴퍼니(GOOD SMILE)', $product->ok_product_maker_name);
    }

    public function test_mark_unseen_offers_unavailable_handles_disappeared_products(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 1차 크롤: 두 상품 적재(둘 다 재고).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '원신 넨도로이드 푸리나', 78000),
            $this->dto('dokidokigoods', 'A2', '원신 넨도로이드 클로린데', 80000),
        ], incremental: false);
        $this->assertSame(2, OtakuOffer::where('ok_offer_available_flg', true)->count());

        // 1차 수집분의 collected_dt 를 과거로 고정(초 단위 컬럼이라 테스트에서 결정적으로 구분).
        OtakuOffer::query()->update(['ok_offer_collected_dt' => Carbon::now()->subDay()]);

        // 2차 전량 크롤: A1만 다시 수집됨(A2는 품절되어 리스트에서 사라짐).
        $runStartedAt = Carbon::now();
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '원신 넨도로이드 푸리나', 78000),
        ], incremental: false);

        $soldOut = $service->markUnseenOffersUnavailable(['dokidokigoods'], $runStartedAt);

        $this->assertSame(1, $soldOut);
        $this->assertFalse((bool) OtakuOffer::where('ok_offer_external_id', 'A2')->first()->ok_offer_available_flg);
        $this->assertTrue((bool) OtakuOffer::where('ok_offer_external_id', 'A1')->first()->ok_offer_available_flg);
    }

    public function test_mark_unseen_skips_shops_that_were_not_crawled(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 따빼몰 상품을 적재해 두고,
        $service->syncProductsAndOffers([
            $this->dto('ttabbaemall', 'B1', '명일방주 아미야 피규어', 50000),
        ], incremental: false);

        OtakuOffer::query()->update(['ok_offer_collected_dt' => Carbon::now()->subDay()]);

        // 도키도키굿즈만 크롤한 회차라면, 따빼몰 오퍼는 건드리지 않아야 한다(전체 품절 오인 방지).
        $runStartedAt = Carbon::now();
        $soldOut = $service->markUnseenOffersUnavailable(['dokidokigoods'], $runStartedAt);

        $this->assertSame(0, $soldOut);
        $this->assertTrue((bool) OtakuOffer::where('ok_offer_external_id', 'B1')->first()->ok_offer_available_flg);
    }

    public function test_unique_constraint_prevents_duplicate_offer_per_shop(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 두 번 동기화해도 (상품,샵) 오퍼는 1건으로 유지(갱신).
        $payload = [$this->dto('dokidokigoods', 'A1', '명일방주 아미야 피규어', 50000)];
        $service->syncProductsAndOffers($payload, incremental: false);
        $service->syncProductsAndOffers([$this->dto('dokidokigoods', 'A1', '명일방주 아미야 피규어', 45000)], incremental: true);

        $this->assertSame(1, OtakuOffer::count());
        $this->assertSame('45000.00', OtakuOffer::first()->ok_offer_price);
    }
}
