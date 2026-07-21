<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
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

    public function test_same_maker_number_different_ip_not_merged(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 제조사가 달라 넨도 번호(No.3057)가 겹치는 전혀 다른 두 상품(미쿠 vs 니케 라피) —
        // 품번만 같고 IP(작품)가 다르면 병합하면 안 된다(실제 운영 사례).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[예약상품/26년 10월~11월 입고예정][굿스마일컴퍼니][보컬로이드] 넨도로이드 No.3057 캐릭터 보컬 시리즈 01: 하츠네 미쿠 안경×카페 Ver.', 70000, categoryCode: 'figure'),
            $this->dto('ttabbaemall', 'B1', '[예약상품/27년 03월~04월 입고예정][굿스마일아츠상하이][승리의 여신 니케] 넨도로이드 No.3057 라피: 레드 후드 Ver.', 72000, categoryCode: 'figure'),
        ], incremental: false);

        $this->assertSame(2, OtakuProduct::count(), '품번이 같아도 다른 작품이면 별도 상품이어야 함');

        // 두 상품 모두 서로 다른 IP로 분류돼 있어야 한다.
        $ipIds = OtakuProduct::pluck('ok_product_ip_id');
        $this->assertCount(2, $ipIds->filter()->unique());
    }

    public function test_same_maker_number_same_ip_absorbs_title_without_ip(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 한쪽 제목에 작품명이 없어 IP가 안 뽑혀도, 같은 품번의 IP付 번들이 하나뿐이면
        // 그쪽으로 흡수돼 동일 상품으로 유지된다(품번 키에 IP를 넣은 데 따른 분리 회귀 방지).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '넨도로이드 No.1955 니시키기 치사토', 65000, categoryCode: 'figure'),
            $this->dto('ttabbaemall', 'B1', '[리코리스 리코일] 넨도로이드 넘버1955 니시키기 치사토 (재판)', 63000, categoryCode: 'figure'),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count(), '같은 IP·같은 품번은 여전히 한 상품으로 묶여야 함');
        $this->assertSame(2, OtakuOffer::count());
        $this->assertSame('nendo_1955', OtakuProduct::first()->ok_product_maker_code);
    }

    public function test_outfit_variant_not_merged_with_base_figure(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 같은 IP·같은 1/7 스케일에서 {캐릭터명...} ⊆ {캐릭터명..., 수영복} 부분집합이어도
        // 의상 변형 키워드가 한쪽에만 있으면 기본판 vs 변형판이므로 병합하지 않는다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '블루 아카이브 아스나 메모리얼 로비 1/7 피규어', 250000, categoryCode: 'figure'),
            $this->dto('ttabbaemall', 'B1', '블루 아카이브 아스나 메모리얼 로비 수영복 Ver. 1/7 피규어', 260000, categoryCode: 'figure'),
        ], incremental: false);

        $this->assertSame(2, OtakuProduct::count(), '의상 변형판은 기본판과 별도 상품이어야 함');
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

    public function test_accessory_case_is_not_merged_with_figure_body_by_maker_code(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 실제 운영 사례: 부속품(전용 케이스) 제목에 본체 품번(넨도로이드 No.1955)이 그대로 들어 있어
        // 품번 매칭으로 본체와 병합돼 가격비교가 오염됐던 케이스 → 두 상품으로 분리 유지돼야 한다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[입고완료][굿스마일컴퍼니][리코리스 리코일] 넨도로이드 No.1955 니시키기 치사토 (재판)', 74000),
            $this->dto('ttabbaemall', 'B1', '판도라 넨도로이드 No.1955 리코리스 리코일 니시키기 치사토 피규어 전용 아크릴 케이스', 19000),
        ], incremental: false);

        $this->assertSame(2, OtakuProduct::count(), '부속품 케이스는 본체와 다른 상품으로 남아야 함');
        $this->assertSame(2, OtakuOffer::count());

        // 라인넘버형 품번(nendo_1955)은 본체만 가진다(부속품 쪽은 버려짐).
        $codes = OtakuProduct::pluck('ok_product_maker_code', 'ok_product_title')->all();
        $this->assertSame('nendo_1955', $codes['[입고완료][굿스마일컴퍼니][리코리스 리코일] 넨도로이드 No.1955 니시키기 치사토 (재판)']);
        $this->assertNull($codes['판도라 넨도로이드 No.1955 리코리스 리코일 니시키기 치사토 피규어 전용 아크릴 케이스']);
    }

    public function test_accessory_with_same_jan_still_matches_across_shops(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // JAN 바코드는 부속품 '자체'의 고유값이므로 버리지 않는다 → 쇼핑몰 간 동일 부속품 매칭은 유지.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '넨도로이드 No.1955 니시키기 치사토 피규어 전용 아크릴 케이스', 19000, makerCode: 'jan_4580590189997'),
            $this->dto('ttabbaemall', 'B1', '니시키기 치사토 넨도로이드 전용 케이스', 18000, makerCode: 'jan_4580590189997'),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
        $this->assertSame('jan_4580590189997', OtakuProduct::first()->ok_product_maker_code);
    }

    public function test_same_normalized_title_different_ip_not_bundled_same_run(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 말머리([주술회전]/[은혼])는 정규화에서 제거돼 두 굿즈의 정규화 키가 같아진다.
        // IP 접미 키가 없으면 다른 작품의 동일 제목 상품이 한 상품으로 합쳐지는 케이스(실제 운영 사례).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[주술회전] 룩업 미니어처 컬렉션 4개입 BOX', 88000),
            $this->dto('ttabbaemall', 'B1', '[은혼] 룩업 미니어처 컬렉션 4개입 BOX', 88000),
        ], incremental: false);

        $this->assertSame(2, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
    }

    public function test_legacy_shared_key_product_splits_by_ip_without_unique_collision(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 과거 상태 재현: 평키(pr_, IP 접미 없음) 시절 두 작품의 동일 제목 굿즈가 한 상품으로 합쳐져 있다.
        $legacy = OtakuProduct::create([
            'ok_product_code' => 'pr_legacyshared',
            'ok_product_title' => '[주술회전] 룩업 미니어처 컬렉션 4개입 BOX',
            'ok_product_ip_id' => OtakuIp::where('ok_ip_code', '주술회전')->first()->ok_ip_id,
            'ok_product_active_flg' => true,
        ]);
        $shopIds = OtakuShop::pluck('ok_shop_id', 'ok_shop_code');
        foreach ([['dokidokigoods', 'A1'], ['ttabbaemall', 'B1']] as [$shopCode, $extId]) {
            OtakuOffer::create([
                'ok_offer_product_id' => $legacy->ok_product_id,
                'ok_offer_shop_id' => $shopIds[$shopCode],
                'ok_offer_external_id' => $extId,
                'ok_offer_currency' => 'KRW',
                'ok_offer_price' => 88000,
                'ok_offer_local_price' => 88000,
                'ok_offer_available_flg' => true,
                'ok_offer_external_url' => 'https://example.com/'.$extId,
                'ok_offer_collected_dt' => Carbon::now(),
            ]);
        }

        // 재크롤: IP 충돌 가드가 은혼 오퍼를 별도 상품으로 분리하며, 유니크 키 충돌(23000) 없이 완료돼야 한다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[주술회전] 룩업 미니어처 컬렉션 4개입 BOX', 88000),
            $this->dto('ttabbaemall', 'B1', '[은혼] 룩업 미니어처 컬렉션 4개입 BOX', 88000),
        ], incremental: true);

        $this->assertSame(2, OtakuProduct::count());
        $jujutsuOffer = OtakuOffer::where('ok_offer_external_id', 'A1')->first();
        $gintamaOffer = OtakuOffer::where('ok_offer_external_id', 'B1')->first();
        $this->assertSame((int) $legacy->ok_product_id, (int) $jujutsuOffer->ok_offer_product_id);
        $this->assertNotSame((int) $legacy->ok_product_id, (int) $gintamaOffer->ok_offer_product_id);
    }

    public function test_ipless_title_joins_single_ip_bundle_same_run(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);

        // 같은 상품인데 한쪽 제목에만 IP 표기가 있는 경우 — IP 접미 키 도입으로 분리되지 않아야 한다(흡수 규칙).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[블루 아카이브] 아스나 클리어 파일', 5000),
            $this->dto('ttabbaemall', 'B1', '아스나 클리어 파일', 4800),
        ], incremental: false);

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
    }

    public function test_recrawl_splits_accessory_offer_stuck_on_figure_product(): void
    {
        $this->seedShops();
        $service = $this->app->make(CrawlSyncService::class);

        // 과거 매칭 오염 상태 재현: 본체 상품 하나에 본체 listing 과 부속품(케이스) listing 오퍼가 함께 붙어 있다.
        $product = OtakuProduct::create([
            'ok_product_code' => 'mkr_old_contaminated',
            'ok_product_title' => '[리코리스 리코일] 넨도로이드 No.1955 니시키기 치사토 (재판)',
            'ok_product_maker_code' => 'nendo_1955',
            'ok_product_active_flg' => true,
        ]);
        $shopIds = OtakuShop::pluck('ok_shop_id', 'ok_shop_code');
        foreach ([['dokidokigoods', 'A1', 74000], ['ttabbaemall', 'B1', 19000]] as [$shopCode, $extId, $price]) {
            OtakuOffer::create([
                'ok_offer_product_id' => $product->ok_product_id,
                'ok_offer_shop_id' => $shopIds[$shopCode],
                'ok_offer_external_id' => $extId,
                'ok_offer_currency' => 'KRW',
                'ok_offer_price' => $price,
                'ok_offer_local_price' => $price,
                'ok_offer_available_flg' => true,
                'ok_offer_external_url' => 'https://example.com/'.$extId,
                'ok_offer_collected_dt' => Carbon::now(),
            ]);
        }

        // 재크롤: 부속품 앵커 가드가 케이스 오퍼를 별도 상품으로 분리한다(자가치유).
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[입고완료][굿스마일컴퍼니][리코리스 리코일] 넨도로이드 No.1955 니시키기 치사토 (재판)', 74000),
            $this->dto('ttabbaemall', 'B1', '판도라 넨도로이드 No.1955 리코리스 리코일 니시키기 치사토 피규어 전용 아크릴 케이스', 19000),
        ], incremental: true);

        $this->assertSame(2, OtakuProduct::count());
        $bodyOffer = OtakuOffer::where('ok_offer_external_id', 'A1')->first();
        $caseOffer = OtakuOffer::where('ok_offer_external_id', 'B1')->first();
        $this->assertSame((int) $product->ok_product_id, (int) $bodyOffer->ok_offer_product_id);
        $this->assertNotSame((int) $bodyOffer->ok_offer_product_id, (int) $caseOffer->ok_offer_product_id);
    }

    public function test_accessory_detection_and_maker_code_sanitizing_rules(): void
    {
        // 부속품 판별(공용 헬퍼): 케이스/아크릴 등 키워드가 있으면 부속품.
        $this->assertTrue(CrawlSyncService::looksLikeAccessory('넨도로이드 No.1955 니시키기 치사토 피규어 전용 아크릴 케이스'));
        $this->assertTrue(CrawlSyncService::looksLikeAccessory('1/7 스케일 피규어 LED 디스플레이 케이스'));
        $this->assertFalse(CrawlSyncService::looksLikeAccessory('[입고완료] 리코리스 리코일 넨도로이드 No.1955 니시키기 치사토 (재판)'));

        // 품번 정제: 부속품 제목의 라인넘버형 품번은 본체 품번이므로 버리고, JAN은 부속품 자체 고유값이라 유지.
        $this->assertNull(CrawlSyncService::sanitizeMakerCode('nendo_1955', '넨도로이드 No.1955 치사토 전용 아크릴 케이스'));
        $this->assertSame('jan_4580590189997', CrawlSyncService::sanitizeMakerCode('jan_4580590189997', '넨도로이드 No.1955 치사토 전용 아크릴 케이스'));
        // 본체 제목이면 라인넘버형 품번도 그대로 유지.
        $this->assertSame('nendo_1955', CrawlSyncService::sanitizeMakerCode('nendo_1955', '리코리스 리코일 넨도로이드 No.1955 니시키기 치사토'));
        $this->assertNull(CrawlSyncService::sanitizeMakerCode(null, '아무 제목'));
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

    // ─────────────────────────────────────────────────────────────────────────
    // 이미지 dHash 자동 병합 (같은 IP + 피규어 + 스케일동일 + 부속품일치 + 변형키워드일치 +
    //  변별토큰 비충돌 가드 통과 + 해밍거리 근접). 네트워크 없이 해시를 직접 세팅해 검증한다.
    // ─────────────────────────────────────────────────────────────────────────

    /** 피규어 상품을 이미지 해시와 함께 직접 시딩(이미지 병합 로직 단위 검증용, 네트워크 미사용). */
    private function figureWithHash(string $title, string $hash, int $ipId, int $cateId): OtakuProduct
    {
        return OtakuProduct::create([
            'ok_product_code' => 'p_'.substr(md5($title), 0, 16),
            'ok_product_title' => $title,
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $cateId,
            'ok_product_ip_id' => $ipId,
            'ok_product_image_hash' => $hash,
        ]);
    }

    /** @return array{0:int, 1:int} [블루아카이브 ip_id, figure cate_id] */
    private function figureRefs(CrawlSyncService $service): array
    {
        $this->seedRefs($service);

        return [
            (int) OtakuIp::where('ok_ip_code', '블루아카이브')->first()->ok_ip_id,
            (int) OtakuCategory::where('ok_category_code', 'figure')->first()->ok_category_id,
        ];
    }

    public function test_image_hash_merges_nonscale_figures_with_divergent_titles(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 같은 팝업퍼레이드 유즈(메이드) 논스케일 피규어인데 제목 조각이 갈려(하나오카 유무) 정규화 키로 안 묶인다.
        // 이미지가 거의 동일하면(해밍 0) 이미지 확증으로 병합돼야 한다.
        $a = $this->figureWithHash('블루 아카이브 POP UP PARADE 하나오카 유즈 메이드 Ver.', 'ffffffffffffffff', $ipId, $cateId);
        $b = $this->figureWithHash('블루 아카이브 팝업 퍼레이드 유즈 메이드 논스케일 피규어', 'ffffffffffffffff', $ipId, $cateId);

        $groups = $service->imageMergeGroups(7);

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing([$a->ok_product_id, $b->ok_product_id], $groups[0]);
    }

    public function test_image_hash_merges_single_distinctive_token_figures(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 프라나 1/7 — 변별 토큰이 캐릭터명 하나뿐이라 시그니처가 비어(퍼지 불가) 미병합되던 실제 사례.
        // 이미지가 거의 동일하면 병합된다.
        $a = $this->figureWithHash('블루 아카이브 프라나 1/7 피규어', 'aaaaaaaaaaaaaaaa', $ipId, $cateId);
        $b = $this->figureWithHash('[입고완료][골든헤드+][블루 아카이브] 프라나 1/7', 'aaaaaaaaaaaaaaaa', $ipId, $cateId);

        $groups = $service->imageMergeGroups(7);

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing([$a->ok_product_id, $b->ok_product_id], $groups[0]);
    }

    public function test_image_hash_does_not_merge_far_hashes(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 같은 캐릭터·스케일이라도 이미지가 다르면(해밍 64) 병합하지 않는다.
        $this->figureWithHash('블루 아카이브 프라나 1/7 피규어', '0000000000000000', $ipId, $cateId);
        $this->figureWithHash('[블루 아카이브] 프라나 1/7', 'ffffffffffffffff', $ipId, $cateId);

        $this->assertSame([], $service->imageMergeGroups(7));
    }

    public function test_image_hash_does_not_merge_different_single_token_characters_even_when_hash_identical(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 변별 토큰이 캐릭터명 하나뿐인 서로 다른 캐릭터(프라나 vs 호시노)는 해시가 같아도 병합 금지
        // (단일 토큰이라도 캐릭터 충돌 가드가 막는다 — 과병합 방어의 핵심).
        $this->figureWithHash('블루 아카이브 프라나 1/7 피규어', 'cccccccccccccccc', $ipId, $cateId);
        $this->figureWithHash('블루 아카이브 호시노 1/7 피규어', 'cccccccccccccccc', $ipId, $cateId);

        $this->assertSame([], $service->imageMergeGroups(7));
    }

    public function test_image_hash_does_not_merge_flat_goods_mislabeled_as_figure(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 실제 회귀: 제목에 '피규어'가 있어 피규어로 오분류된 '아크릴 스탠드'(평면 굿즈). 같은 라인이라
        // 캐릭터가 달라도 이미지가 거의 같고, 1글자 캐릭터명(빔)은 토큰이 없어 캐릭터 가드가 못 서
        // union-find 로 여러 캐릭터가 한 그룹으로 전이 병합되던 사고. looksLikeAccessory(아크릴/스탠드)로
        // 이미지 병합에서 통째 제외돼야 한다(진짜 3D 피규어는 accessory 키워드가 없어 영향 없음).
        $this->figureWithHash('체인소 맨 극장판 레제편 팝업 스토어 아크릴 스탠드 피규어 - 파워', 'dddddddddddddddd', $ipId, $cateId);
        $this->figureWithHash('체인소 맨 극장판 레제편 팝업 스토어 아크릴 스탠드 피규어 - 빔', 'dddddddddddddddd', $ipId, $cateId);
        $this->figureWithHash('체인소 맨 극장판 레제편 팝업 스토어 아크릴 스탠드 피규어 - 덴지', 'dddddddddddddddd', $ipId, $cateId);

        $this->assertSame([], $service->imageMergeGroups(7));
    }

    public function test_image_merge_is_blocked_by_scale_accessory_variant_and_character_guards(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 모두 같은 IP·피규어·동일 해시지만, 각 쌍은 가드로 막혀 어떤 것도 병합되면 안 된다.
        $hash = 'dddddddddddddddd';
        // 스케일 다름(1/7 vs 1/4)
        $this->figureWithHash('블루 아카이브 아스나 1/7 피규어', $hash, $ipId, $cateId);
        $this->figureWithHash('블루 아카이브 아스나 1/4 피규어', $hash, $ipId, $cateId);
        // 부속품 vs 본체(같은 시로코)
        $this->figureWithHash('블루 아카이브 시로코 1/7 피규어', $hash, $ipId, $cateId);
        $this->figureWithHash('블루 아카이브 시로코 1/7 피규어 전용 아크릴 케이스', $hash, $ipId, $cateId);
        // 의상 변형 불일치(같은 미카, 기본 vs 수영복)
        $this->figureWithHash('블루 아카이브 미카 1/7 피규어', $hash, $ipId, $cateId);
        $this->figureWithHash('블루 아카이브 미카 수영복 1/7 피규어', $hash, $ipId, $cateId);

        $this->assertSame([], $service->imageMergeGroups(7), '가드가 모든 쌍을 막아 병합 그룹이 없어야 함');
    }

    public function test_image_merge_ignores_non_figure_and_missing_or_bad_hashes(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);
        $goodsCateId = (int) OtakuCategory::where('ok_category_code', 'goods')->first()->ok_category_id;

        // 굿즈 카테고리는 캐릭터 일러스트를 공유하는 변형이 많아 이미지 자동병합 대상에서 제외.
        $this->figureWithHash('블루 아카이브 아로나 클리어 파일', 'eeeeeeeeeeeeeeee', $ipId, $goodsCateId);
        $this->figureWithHash('블루 아카이브 아로나 클리어 파일 라지', 'eeeeeeeeeeeeeeee', $ipId, $goodsCateId);
        // 해시 형식이 잘못됐거나(길이 미달) 실패 마커('')는 후보에서 제외.
        $this->figureWithHash('블루 아카이브 히나 1/7 피규어', 'zzzz', $ipId, $cateId);
        $this->figureWithHash('블루 아카이브 히나 1/7 스케일 피규어', '', $ipId, $cateId);

        $this->assertSame([], $service->imageMergeGroups(7));
    }

    public function test_image_merge_threshold_seven_includes_hamming_seven(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 같은 프라나 1/7 인데 쇼핑몰 사진이 살짝 달라 dHash 해밍거리 7(하위 바이트 0x7f = 7비트) — 임계값 7이면 병합.
        $a = $this->figureWithHash('블루 아카이브 프라나 1/7 피규어', '0000000000000000', $ipId, $cateId);
        $b = $this->figureWithHash('[블루 아카이브] 프라나 1/7', '000000000000007f', $ipId, $cateId);

        $groups = $service->imageMergeGroups(7);

        $this->assertCount(1, $groups);
        $this->assertEqualsCanonicalizing([$a->ok_product_id, $b->ok_product_id], $groups[0]);
    }

    public function test_image_merge_threshold_seven_excludes_hamming_eight(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        [$ipId, $cateId] = $this->figureRefs($service);

        // 해밍거리 8(하위 바이트 0xff = 8비트)은 임계값 7을 넘어 병합하지 않는다.
        $this->figureWithHash('블루 아카이브 프라나 1/7 피규어', '0000000000000000', $ipId, $cateId);
        $this->figureWithHash('[블루 아카이브] 프라나 1/7', '00000000000000ff', $ipId, $cateId);

        $this->assertSame([], $service->imageMergeGroups(7));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1/N 스케일 = 피규어: 동기화 시 카테고리 승격(스케일 표기 + 부속품 아님 → 피규어).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_scale_title_is_promoted_to_figure_category_on_sync(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);
        $figureCateId = (int) OtakuCategory::where('ok_category_code', 'figure')->first()->ok_category_id;

        // 쇼핑몰이 '기타(other)'로 라벨링한 스케일 피규어 — 제목에 "피규어" 단어가 없어도 1/N 스케일이면 피규어로 승격.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '[골든헤드+][블루 아카이브] 프라나 1/7', 250000, categoryCode: 'other'),
        ], incremental: false);

        $this->assertSame($figureCateId, (int) OtakuProduct::first()->ok_product_cate_id);
    }

    public function test_scale_accessory_is_not_promoted_to_figure_on_sync(): void
    {
        $service = $this->app->make(CrawlSyncService::class);
        $this->seedRefs($service);
        $otherCateId = (int) OtakuCategory::where('ok_category_code', 'other')->first()->ok_category_id;

        // 스케일 표기가 있어도 부속품(전용 디스플레이 케이스)은 피규어로 승격하지 않는다.
        $service->syncProductsAndOffers([
            $this->dto('dokidokigoods', 'A1', '블루 아카이브 프라나 1/7 스케일 피규어 LED 디스플레이 케이스', 90000, categoryCode: 'other'),
        ], incremental: false);

        $this->assertSame($otherCateId, (int) OtakuProduct::first()->ok_product_cate_id);
    }

    public function test_is_figure_scale_accepts_1_over_n_but_rejects_dates_and_ratios(): void
    {
        // 승격은 분자 1의 1/N(N=2~12)만 — 날짜(25/05)·비율(2/3)·대형모델(1/144)·부속품은 제외해
        // 봉제인형·CD·메달이 피규어로 오승격되던 것을 막는다.
        $this->assertTrue(CrawlSyncService::isFigureScale('블루 아카이브 프라나 1/7 피규어'));
        $this->assertTrue(CrawlSyncService::isFigureScale('코토부키야 걸판처 다즐링 1/8 스케일'));
        $this->assertTrue(CrawlSyncService::isFigureScale('바니 아오이 1/6'));

        $this->assertFalse(CrawlSyncService::isFigureScale('윈브레 봉제인형 사쿠라 하루카 ~25/05/03'), '날짜');
        $this->assertFalse(CrawlSyncService::isFigureScale('공식 앨범 CD 토모다치 2/3 특전'), '분자 1 아닌 비율');
        $this->assertFalse(CrawlSyncService::isFigureScale('건담 RG 1/144 프라모델'), 'N>12 대형 모델');
        $this->assertFalse(CrawlSyncService::isFigureScale('프라나 1/7 스케일 LED 디스플레이 케이스'), '부속품');
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
