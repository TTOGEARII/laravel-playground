<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): array
    {
        $category = OtakuCategory::create([
            'ok_category_code' => 'goods',
            'ok_category_label' => '굿즈',
            'ok_category_sort' => 1,
        ]);

        $shopA = OtakuShop::create([
            'ok_shop_code' => 'dokidokigoods',
            'ok_shop_name' => '도키도키굿즈',
            'ok_shop_active_flg' => true,
        ]);
        $shopB = OtakuShop::create([
            'ok_shop_code' => 'animate',
            'ok_shop_name' => '애니메이트',
            'ok_shop_active_flg' => true,
        ]);

        $product = OtakuProduct::create([
            'ok_product_code' => 'pr_aaa',
            'ok_product_title' => '러브라이브 아크릴 스탠드',
            'ok_product_brand_label' => '러브라이브',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $category->ok_category_id,
        ]);

        OtakuOffer::create([
            'ok_offer_product_id' => $product->ok_product_id,
            'ok_offer_shop_id' => $shopA->ok_shop_id,
            'ok_offer_external_id' => 'aaa-shopA',
            'ok_offer_currency' => 'KRW',
            'ok_offer_price' => 15000,
            'ok_offer_available_flg' => true,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $product->ok_product_id,
            'ok_offer_shop_id' => $shopB->ok_shop_id,
            'ok_offer_external_id' => 'aaa-shopB',
            'ok_offer_currency' => 'KRW',
            'ok_offer_price' => 13000,
            'ok_offer_available_flg' => true,
        ]);

        return compact('category', 'shopA', 'shopB', 'product');
    }

    public function test_products_endpoint_returns_data_and_meta(): void
    {
        $this->seedCatalog();

        $this->getJson('/api/otaku-shop/products')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_keyword_filter_matches_title(): void
    {
        $this->seedCatalog();

        $this->getJson('/api/otaku-shop/products?keyword=러브라이브')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/otaku-shop/products?keyword=존재하지않는상품')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_category_and_shop_filters(): void
    {
        $data = $this->seedCatalog();

        $this->getJson('/api/otaku-shop/products?category_id='.$data['category']->ok_category_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/otaku-shop/products?shop_id[]='.$data['shopA']->ok_shop_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_compared_only_returns_products_with_multiple_shops(): void
    {
        $data = $this->seedCatalog();

        // 한 쇼핑몰에만 오퍼가 있는 상품(비교 불가) 추가.
        $single = OtakuProduct::create([
            'ok_product_code' => 'pr_single',
            'ok_product_title' => '단일샵 키링',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $single->ok_product_id,
            'ok_offer_shop_id' => $data['shopA']->ok_shop_id,
            'ok_offer_external_id' => 'single-shopA',
            'ok_offer_currency' => 'KRW',
            'ok_offer_price' => 8000,
            'ok_offer_available_flg' => true,
        ]);

        // 전체는 2건, 비교가능만은 1건(2개 샵 상품).
        $this->getJson('/api/otaku-shop/products')
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/otaku-shop/products?compared_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_aaa');
    }

    public function test_price_sort_orders_by_lowest_available_offer(): void
    {
        $data = $this->seedCatalog(); // pr_aaa: 최저가 13000

        // 더 저렴한 상품(최저 5000)과 더 비싼 상품(최저 20000) 추가.
        $cheap = OtakuProduct::create([
            'ok_product_code' => 'pr_cheap',
            'ok_product_title' => '저가 키링',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $cheap->ok_product_id,
            'ok_offer_shop_id' => $data['shopA']->ok_shop_id,
            'ok_offer_external_id' => 'cheap-shopA',
            'ok_offer_currency' => 'KRW', 'ok_offer_price' => 5000, 'ok_offer_available_flg' => true,
        ]);

        $pricey = OtakuProduct::create([
            'ok_product_code' => 'pr_pricey',
            'ok_product_title' => '고가 피규어',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $pricey->ok_product_id,
            'ok_offer_shop_id' => $data['shopA']->ok_shop_id,
            'ok_offer_external_id' => 'pricey-shopA',
            'ok_offer_currency' => 'KRW', 'ok_offer_price' => 20000, 'ok_offer_available_flg' => true,
        ]);

        // 최저가 순: cheap(5000) → aaa(13000) → pricey(20000)
        $this->getJson('/api/otaku-shop/products?sort=price_asc')
            ->assertOk()
            ->assertJsonPath('data.0.ok_product_code', 'pr_cheap')
            ->assertJsonPath('data.2.ok_product_code', 'pr_pricey');

        // 가격 높은 순: pricey → aaa → cheap
        $this->getJson('/api/otaku-shop/products?sort=price_desc')
            ->assertOk()
            ->assertJsonPath('data.0.ok_product_code', 'pr_pricey')
            ->assertJsonPath('data.2.ok_product_code', 'pr_cheap');
    }

    public function test_upcoming_filter_returns_only_future_release_products(): void
    {
        $data = $this->seedCatalog();

        OtakuProduct::create([
            'ok_product_code' => 'pr_future',
            'ok_product_title' => '미래 발매 피규어',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
            'ok_product_release_date' => now()->addMonth()->toDateString(),
        ]);
        OtakuProduct::create([
            'ok_product_code' => 'pr_past',
            'ok_product_title' => '과거 발매 피규어',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
            'ok_product_release_date' => now()->subMonth()->toDateString(),
        ]);

        $this->getJson('/api/otaku-shop/products?upcoming=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_future');
    }

    public function test_per_page_is_capped_at_50(): void
    {
        $this->seedCatalog();

        $this->getJson('/api/otaku-shop/products?per_page=999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_categories_and_shops_endpoints(): void
    {
        $this->seedCatalog();

        $this->getJson('/api/otaku-shop/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/otaku-shop/shops')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_soldout_offer_is_returned_for_display(): void
    {
        $data = $this->seedCatalog();

        // B샵(애니메이트) 오퍼를 품절로 변경 → 숨기지 않고 품절로 함께 내려와야 한다.
        OtakuOffer::where('ok_offer_product_id', $data['product']->ok_product_id)
            ->where('ok_offer_shop_id', $data['shopB']->ok_shop_id)
            ->update(['ok_offer_available_flg' => false]);

        $res = $this->getJson('/api/otaku-shop/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // 두 오퍼(판매중 1 + 품절 1)가 모두 응답에 포함된다.
        $offers = $res->json('data.0.offers');
        $this->assertCount(2, $offers);

        $flags = collect($offers)->keyBy('ok_offer_shop_id');
        $this->assertTrue((bool) $flags[$data['shopA']->ok_shop_id]['ok_offer_available_flg']);
        $this->assertFalse((bool) $flags[$data['shopB']->ok_shop_id]['ok_offer_available_flg']);

        // 판매중 오퍼가 먼저 정렬된다.
        $this->assertTrue((bool) $offers[0]['ok_offer_available_flg']);
    }

    public function test_compared_only_includes_products_with_a_soldout_offer(): void
    {
        $data = $this->seedCatalog();

        // 2개 샵 상품 중 한쪽을 품절로 바꿔도 '가격 vs 품절'로 비교 노출되어야 한다.
        OtakuOffer::where('ok_offer_product_id', $data['product']->ok_product_id)
            ->where('ok_offer_shop_id', $data['shopB']->ok_shop_id)
            ->update(['ok_offer_available_flg' => false]);

        $this->getJson('/api/otaku-shop/products?compared_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_aaa');
    }

    public function test_created_desc_sort_orders_by_registration_date(): void
    {
        $data = $this->seedCatalog(); // pr_aaa: 가장 먼저 등록

        // 등록일을 명시적으로 벌려 둔다(seed 상품은 과거, 신규 상품은 최신).
        OtakuProduct::where('ok_product_code', 'pr_aaa')
            ->update(['create_dt' => now()->subDays(3)]);

        $newer = OtakuProduct::create([
            'ok_product_code' => 'pr_newer',
            'ok_product_title' => '신규 등록 굿즈',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
        $newer->update(['create_dt' => now()]);

        // 최근 등록순: pr_newer 가 먼저.
        $this->getJson('/api/otaku-shop/products?sort=created_desc')
            ->assertOk()
            ->assertJsonPath('data.0.ok_product_code', 'pr_newer')
            ->assertJsonPath('data.1.ok_product_code', 'pr_aaa');
    }

    public function test_in_stock_only_excludes_fully_soldout_products(): void
    {
        $data = $this->seedCatalog();

        // 모든 오퍼가 품절인 상품(재고 없음) 추가.
        $soldout = OtakuProduct::create([
            'ok_product_code' => 'pr_soldout',
            'ok_product_title' => '전량 품절 피규어',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $soldout->ok_product_id,
            'ok_offer_shop_id' => $data['shopA']->ok_shop_id,
            'ok_offer_external_id' => 'soldout-shopA',
            'ok_offer_currency' => 'KRW', 'ok_offer_price' => 9000, 'ok_offer_available_flg' => false,
        ]);

        // 전체는 2건, 재고 있는 상품만은 1건(pr_aaa).
        $this->getJson('/api/otaku-shop/products')
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/otaku-shop/products?in_stock_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_aaa');
    }

    /** 검색 개선 검증용: 블루아카이브 IP + 상품 2개(붙여쓴 제목/띄어쓴 제목) 시딩. */
    private function seedSearchTargets(array $data): void
    {
        $ip = \App\Models\OtakuShop\OtakuIp::create([
            'ok_ip_code' => '블루아카이브', 'ok_ip_label' => '블루아카이브', 'ok_ip_sort' => 1,
        ]);
        OtakuProduct::create([
            'ok_product_code' => 'pr_prana',
            'ok_product_title' => '블루아카이브 초코푸니 프라나 인형',   // 붙여쓴 IP 표기
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
            'ok_product_ip_id' => $ip->ok_ip_id,
        ]);
        OtakuProduct::create([
            'ok_product_code' => 'pr_yuzu',
            'ok_product_title' => '블루 아카이브 팝업 퍼레이드 유즈',    // 띄어쓴 IP 표기, IP 미분류
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
    }

    public function test_keyword_tokens_match_regardless_of_order_and_spacing(): void
    {
        $this->seedSearchTargets($this->seedCatalog());

        // 어순 뒤집기 + 띄어쓰기 다른 IP 표기("블루 아카이브" ↔ 제목은 "블루아카이브")도 찾아야 한다.
        $this->getJson('/api/otaku-shop/products?keyword='.urlencode('프라나 블루 아카이브'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_prana');
    }

    public function test_keyword_ip_abbreviation_expands_via_aliases(): void
    {
        $this->seedSearchTargets($this->seedCatalog());

        // 줄임말 "블아" → ip_aliases 로 확장: IP 분류된 상품 + 별칭 표기 제목(미분류) 모두 매칭.
        $this->getJson('/api/otaku-shop/products?keyword='.urlencode('블아'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // 줄임말 + 캐릭터 조합("블아 유즈")도 동작해야 한다.
        $this->getJson('/api/otaku-shop/products?keyword='.urlencode('블아 유즈'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_yuzu');
    }

    public function test_spaced_and_compact_ip_search_return_same_results(): void
    {
        $data = $this->seedCatalog();
        $this->seedSearchTargets($data);
        // 제목엔 줄임말(블아)뿐이고 IP 분류만 된 상품 — 띄어쓴 "블루 아카이브" 검색에서도
        // 연속 토큰 IP 인식으로 잡혀야 붙여쓴 검색과 결과가 같아진다.
        $ip = \App\Models\OtakuShop\OtakuIp::where('ok_ip_code', '블루아카이브')->first();
        OtakuProduct::create([
            'ok_product_code' => 'pr_shiroko',
            'ok_product_title' => '블아 굿즈 시로코 아크릴 스탠드',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
            'ok_product_ip_id' => $ip->ok_ip_id,
        ]);

        $compact = $this->getJson('/api/otaku-shop/products?keyword='.urlencode('블루아카이브'))
            ->assertOk()->json('meta.total');
        $spaced = $this->getJson('/api/otaku-shop/products?keyword='.urlencode('블루 아카이브'))
            ->assertOk()->json('meta.total');

        $this->assertSame(3, $compact, '붙여쓴 IP 검색은 IP 상품 3개 전부');
        $this->assertSame($compact, $spaced, '띄어쓴 IP 검색과 붙여쓴 IP 검색 결과가 같아야 함');
    }

    public function test_compact_character_name_matches_spaced_title(): void
    {
        $data = $this->seedCatalog();
        OtakuProduct::create([
            'ok_product_code' => 'pr_rin',
            'ok_product_title' => '보컬로이드 카가미네 린 넨도로이드',  // 캐릭터명이 띄어쓰기된 제목
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);

        // 캐릭터명은 IP 별칭 사전이 없으므로, 붙여쓴 검색("카가미네린")은 공백 제거 제목 대조로 찾아야 한다.
        $this->getJson('/api/otaku-shop/products?keyword='.urlencode('카가미네린'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_rin');

        // 띄어쓴 검색과 결과 동일.
        $this->getJson('/api/otaku-shop/products?keyword='.urlencode('카가미네 린'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /** 해외관 검증용: 해외 샵(JPY) + 해외 오퍼만 가진 상품 + JPY 환율 시딩. */
    private function seedGlobalShop(array $data): \App\Models\OtakuShop\OtakuShop
    {
        $global = OtakuShop::create([
            'ok_shop_code' => 'amiami',
            'ok_shop_name' => '아미아미',
            'ok_shop_region' => 'global',
            'ok_shop_currency' => 'JPY',
            'ok_shop_active_flg' => true,
        ]);
        $overseasOnly = OtakuProduct::create([
            'ok_product_code' => 'pr_jp_only',
            'ok_product_title' => 'Blue Archive Toki Bunny Girl 1/7 Figure',
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $data['category']->ok_category_id,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $overseasOnly->ok_product_id,
            'ok_offer_shop_id' => $global->ok_shop_id,
            'ok_offer_external_id' => 'FIGURE-1',
            'ok_offer_currency' => 'JPY',
            'ok_offer_price' => 13980,
            'ok_offer_available_flg' => true,
        ]);
        \App\Models\OtakuShop\OtakuExchangeRate::create(['ok_rate_currency' => 'JPY', 'ok_rate_krw' => 9.0]);

        return $global;
    }

    public function test_region_filter_splits_domestic_and_global_products(): void
    {
        $data = $this->seedCatalog();          // 국내 샵 오퍼 상품(pr_aaa)
        $this->seedGlobalShop($data);          // 해외 샵 오퍼 상품(pr_jp_only)

        // 국내관: 국내 오퍼 보유 상품만.
        $this->getJson('/api/otaku-shop/products?region=kr')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_aaa');

        // 해외관: 해외 오퍼 보유 상품만.
        $this->getJson('/api/otaku-shop/products?region=global')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ok_product_code', 'pr_jp_only');

        // region 없으면 전체(하위 호환).
        $this->getJson('/api/otaku-shop/products')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_global_offer_includes_krw_converted_price(): void
    {
        $data = $this->seedCatalog();
        $this->seedGlobalShop($data);

        $res = $this->getJson('/api/otaku-shop/products?region=global')->assertOk();
        $offer = $res->json('data.0.offers.0');

        $this->assertSame('JPY', $offer['ok_offer_currency']);
        $this->assertEqualsWithDelta(125820.0, $offer['ok_offer_price_krw'], 0.01, '¥13,980 × ₩9.0 환산가 병기');
    }

    public function test_shops_endpoint_filters_by_region(): void
    {
        $data = $this->seedCatalog();
        $this->seedGlobalShop($data);

        $this->getJson('/api/otaku-shop/shops?region=global')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ok_shop_code', 'amiami');
        $this->getJson('/api/otaku-shop/shops?region=kr')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_global_page_renders(): void
    {
        $this->get('/otaku-shop/global')->assertOk()->assertSee('해외관');
        $this->get('/otaku-shop')->assertOk()->assertSee('otaku-shop-app', false);
    }
}
