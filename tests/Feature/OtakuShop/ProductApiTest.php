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
}
