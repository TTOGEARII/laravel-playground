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
            'ok_offer_currency' => 'KRW',
            'ok_offer_price' => 15000,
            'ok_offer_available_flg' => true,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $product->ok_product_id,
            'ok_offer_shop_id' => $shopB->ok_shop_id,
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
}
