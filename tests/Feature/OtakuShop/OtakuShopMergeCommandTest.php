<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtakuShopMergeCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $shopA;

    private int $shopB;

    private int $cateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopA = OtakuShop::create(['ok_shop_code' => 'dokidokigoods', 'ok_shop_name' => '도키도키굿즈', 'ok_shop_active_flg' => true])->ok_shop_id;
        $this->shopB = OtakuShop::create(['ok_shop_code' => 'ttabbaemall', 'ok_shop_name' => '따빼몰', 'ok_shop_active_flg' => true])->ok_shop_id;
        $this->cateId = OtakuCategory::create(['ok_category_code' => 'figure', 'ok_category_label' => '피규어', 'ok_category_sort' => 1])->ok_category_id;
    }

    private function productWithOffer(string $title, int $shopId, string $extId, float $price): OtakuProduct
    {
        $p = OtakuProduct::create([
            'ok_product_code' => 'p_'.substr(md5($title.$extId), 0, 12),
            'ok_product_title' => $title,
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $this->cateId,
        ]);
        OtakuOffer::create([
            'ok_offer_product_id' => $p->ok_product_id,
            'ok_offer_shop_id' => $shopId,
            'ok_offer_external_id' => $extId,
            'ok_offer_currency' => 'KRW',
            'ok_offer_price' => $price,
            'ok_offer_available_flg' => true,
        ]);

        return $p;
    }

    public function test_merge_combines_products_into_one(): void
    {
        // 자동으론 못 묶는 케이스(쇼핑몰이 다른 사진 사용)를 수동 병합.
        $a = $this->productWithOffer('블루 아카이브 프라나 1/7 피규어', $this->shopA, 'A1', 250000);
        $b = $this->productWithOffer('[골든헤드] 블루 아카이브 프라나 1/7', $this->shopB, 'B1', 240000);

        $this->artisan('otaku-shop:merge', ['ids' => [$a->ok_product_id, $b->ok_product_id]])->assertSuccessful();

        $this->assertSame(1, OtakuProduct::count(), '두 상품이 하나로 병합돼야 함');
        $this->assertSame(2, OtakuOffer::count(), '오퍼는 보존돼 canonical 로 이동');
        $this->assertSame(2, (int) OtakuProduct::first()->offers()->count());
    }

    public function test_dry_run_does_not_merge(): void
    {
        $a = $this->productWithOffer('상품 A', $this->shopA, 'A1', 100);
        $b = $this->productWithOffer('상품 B', $this->shopB, 'B1', 200);

        $this->artisan('otaku-shop:merge', ['ids' => [$a->ok_product_id, $b->ok_product_id], '--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, OtakuProduct::count(), 'dry-run 은 병합하지 않는다');
    }

    public function test_missing_id_fails_without_side_effect(): void
    {
        $a = $this->productWithOffer('상품 A', $this->shopA, 'A1', 100);

        $this->artisan('otaku-shop:merge', ['ids' => [$a->ok_product_id, 999999]])->assertFailed();

        $this->assertSame(1, OtakuProduct::count(), '없는 ID 가 있으면 아무것도 병합하지 않는다');
    }
}
