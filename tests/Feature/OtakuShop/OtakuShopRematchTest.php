<?php

namespace Tests\Feature\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtakuShopRematchTest extends TestCase
{
    use RefreshDatabase;

    private int $shopA;

    private int $shopB;

    private int $cateId;

    private int $ipId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shopA = OtakuShop::create(['ok_shop_code' => 'dokidokigoods', 'ok_shop_name' => '도키도키굿즈', 'ok_shop_active_flg' => true])->ok_shop_id;
        $this->shopB = OtakuShop::create(['ok_shop_code' => 'ttabbaemall', 'ok_shop_name' => '따빼몰', 'ok_shop_active_flg' => true])->ok_shop_id;
        $this->cateId = OtakuCategory::create(['ok_category_code' => 'figure', 'ok_category_label' => '피규어', 'ok_category_sort' => 1])->ok_category_id;
        $this->ipId = OtakuIp::create(['ok_ip_code' => '블루아카이브', 'ok_ip_label' => '블루아카이브', 'ok_ip_sort' => 1])->ok_ip_id;
    }

    private function product(string $code, string $title, ?string $makerCode, int $shopId, string $extId, float $price): OtakuProduct
    {
        $p = OtakuProduct::create([
            'ok_product_code' => $code,
            'ok_product_title' => $title,
            'ok_product_maker_code' => $makerCode,
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $this->cateId,
            'ok_product_ip_id' => $this->ipId,
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

    public function test_rematch_merges_by_recovered_maker_code(): void
    {
        // No. 표기로 예전엔 품번을 못 뽑아 따로 적재된 두 상품.
        $this->product('p1', '블루 아카이브 넨도로이드 2611 이치노세 아스나', 'nendo_2611', $this->shopA, 'A1', 70000);
        $this->product('p2', '블루 아카이브 넨도로이드 No.2611 이치노세 아스나', null, $this->shopB, 'B1', 68000);

        $this->artisan('otaku-shop:rematch')->assertSuccessful();

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
        $this->assertSame('nendo_2611', OtakuProduct::first()->ok_product_maker_code);
    }

    public function test_rematch_merges_by_name_containment(): void
    {
        // maker code 없고 토큰 1개 차이지만 같은 ip+카테고리(피규어) + 스케일 + 포함관계.
        $this->product('p1', '블루 아카이브 아스나 교복 메모리얼 로비 1/7 피규어', null, $this->shopA, 'A1', 250000);
        $this->product('p2', '블루 아카이브 아스나 교복 메모리얼 로비 교스나 1/7 스케일 피규어', null, $this->shopB, 'B1', 240000);

        $this->artisan('otaku-shop:rematch')->assertSuccessful();

        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
    }

    public function test_dry_run_does_not_merge(): void
    {
        $this->product('p1', '블루 아카이브 넨도로이드 2611 아스나', 'nendo_2611', $this->shopA, 'A1', 70000);
        $this->product('p2', '블루 아카이브 넨도로이드 No.2611 아스나', null, $this->shopB, 'B1', 68000);

        $this->artisan('otaku-shop:rematch --dry-run')->assertSuccessful();

        $this->assertSame(2, OtakuProduct::count());
    }
}
