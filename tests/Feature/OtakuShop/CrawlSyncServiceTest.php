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

    /** 이름 유사(포함관계) 매칭은 ip+카테고리가 필요하므로 카테고리·IP 사전을 함께 시드한다. */
    private function seedRefs(CrawlSyncService $service): void
    {
        $this->seedShops();
        $service->syncCategories();
        $service->syncIps();
    }

    private function dto(string $shop, string $extId, string $title, float $price, bool $available = true, ?string $makerCode = null, ?string $maker = null, string $categoryCode = 'goods'): CrawledProductDto
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
            categoryCode: $categoryCode,
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

    public function test_cross_shop_matches_nendo_number_with_no_dot_prefix(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // "넨도로이드 2611"과 "넨도로이드 No.2611"은 같은 품번 → 동일 상품.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[입고 완료] 블루 아카이브 굿스마일 컴퍼니 넨도로이드 2611 피규어 - 이치노세 아스나', 70000),
            $this->dto('ttabbaemall', 'B1', '[예약] 블루 아카이브 넨도로이드 No.2611 이치노세 아스나', 68000),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
        $this->assertSame('nendo_2611', OtakuProduct::first()->ok_product_maker_code);
    }

    public function test_cross_shop_matches_by_name_containment_same_ip_and_category(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // maker code 없고 토큰 1개(교스나) 차이지만, 같은 ip+카테고리(피규어) + 포함관계 → 동일 상품.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[예약] 블루 아카이브 아스나 교복 메모리얼 로비 Ver. 1/7 피규어', 250000, categoryCode: 'figure'),
            $this->dto('ttabbaemall', 'B1', '블루 아카이브 굿스마일 아스나 교복 메모리얼 로비 교스나 1/7 스케일 피규어', 240000, categoryCode: 'figure'),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
    }

    public function test_different_character_not_merged_by_containment(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 같은 ip+카테고리·같은 변형이라도 캐릭터(아스나 vs 시로코)가 다르면 묶이지 않는다(과병합 방지).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '블루 아카이브 아스나 교복 메모리얼 로비 1/7 피규어', 250000, categoryCode: 'figure'),
            $this->dto('ttabbaemall', 'B1', '블루 아카이브 시로코 교복 메모리얼 로비 1/7 피규어', 240000, categoryCode: 'figure'),
        ], incremental: false);

        $this->assertSame(2, OtakuProduct::count());
    }

    public function test_same_scale_figure_merges_despite_split_word_and_conjugation(): void
    {
        OtakuShop::create(['ok_shop_code' => 'animate', 'ok_shop_name' => '애니메이트', 'ok_shop_active_flg' => true]);
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 같은 '아리스 슈퍼노바 각성' 1/7 피규어인데 표기만 다름:
        //  - 슈퍼노바 vs '슈퍼 노바'(분할결합)  - 각성하라 vs 각성입니다(어미=같은 단어 변형)
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[예약]블루 아카이브 아리스 각성하라 슈퍼 노바 1/7 피규어', 250000, categoryCode: 'figure'),
            $this->dto('animate', 'B1', '블루 아카이브 아리스 각성하라 슈퍼노바 1/7 스케일 피규어', 250000, categoryCode: 'figure'),
            $this->dto('ttabbaemall', 'C1', '[27년 07월 발매] 블루 아카이브 굿스마일 컴퍼니 1/7 스케일 피규어 - 아리스 슈퍼노바, 각성입니다! Ver.', 240000, categoryCode: 'figure'),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count(), '세 표기가 동일 상품으로 묶여 가격비교돼야 함');
        $this->assertSame(3, OtakuOffer::count());
    }

    public function test_goods_category_is_not_fuzzy_merged_even_when_containment(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 굿즈는 캐릭터/번호만 다른 변형이 많아 이름 유사 매칭을 적용하지 않는다(포함관계여도 별도 상품).
        // 추가 토큰(아키하바라)은 불용어가 아니라 시그니처가 달라져 정확 매칭으로도 안 묶인다 → 포함관계 매칭만이 묶을 수 있는 케이스.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '블루 아카이브 아스나 클리어 파일', 5000, categoryCode: 'goods'),
            $this->dto('ttabbaemall', 'B1', '블루 아카이브 아스나 아키하바라 클리어 파일', 4500, categoryCode: 'goods'),
        ], incremental: false);

        $this->assertSame(2, OtakuProduct::count());
    }

    public function test_merge_products_transfers_offers_and_dedupes_per_shop(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);
        $shopA = OtakuShop::where('ok_shop_code', 'dokidokigoods')->first()->ok_shop_id;
        $shopB = OtakuShop::where('ok_shop_code', 'ttabbaemall')->first()->ok_shop_id;

        $canonical = OtakuProduct::create(['ok_product_code' => 'p_canon', 'ok_product_title' => '교복 아스나 피규어', 'ok_product_active_flg' => true]);
        $dup = OtakuProduct::create(['ok_product_code' => 'p_dup', 'ok_product_title' => '교복 아스나 교스나 피규어', 'ok_product_active_flg' => true]);

        // canonical: 도키도키 1건. dup: 도키도키(같은 샵, 더 쌈) + 따빼몰 1건.
        OtakuOffer::create(['ok_offer_product_id' => $canonical->ok_product_id, 'ok_offer_shop_id' => $shopA, 'ok_offer_external_id' => 'A1', 'ok_offer_currency' => 'KRW', 'ok_offer_price' => 250000, 'ok_offer_available_flg' => true]);
        OtakuOffer::create(['ok_offer_product_id' => $dup->ok_product_id, 'ok_offer_shop_id' => $shopA, 'ok_offer_external_id' => 'A2', 'ok_offer_currency' => 'KRW', 'ok_offer_price' => 240000, 'ok_offer_available_flg' => true]);
        OtakuOffer::create(['ok_offer_product_id' => $dup->ok_product_id, 'ok_offer_shop_id' => $shopB, 'ok_offer_external_id' => 'B1', 'ok_offer_currency' => 'KRW', 'ok_offer_price' => 230000, 'ok_offer_available_flg' => true]);

        $service->mergeProducts($canonical, [$dup]);

        // 중복 상품은 삭제되고, 오퍼는 canonical로 이전 + 같은 샵(도키도키)은 1건(더 싼 240000)만 남는다.
        $this->assertNull(OtakuProduct::find($dup->ok_product_id));
        $this->assertSame(2, OtakuOffer::where('ok_offer_product_id', $canonical->ok_product_id)->count());
        $this->assertSame('240000.00', OtakuOffer::where('ok_offer_product_id', $canonical->ok_product_id)->where('ok_offer_shop_id', $shopA)->first()->ok_offer_price);
        $this->assertSame(0, OtakuOffer::where('ok_offer_shop_id', $shopA)->where('ok_offer_external_id', 'A1')->count());
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
