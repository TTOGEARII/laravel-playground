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
        return $this->productWithIp($code, $title, $makerCode, $shopId, $extId, $price, $this->ipId);
    }

    /** IP를 명시(null 포함)해 상품+오퍼를 시딩한다(품번 IP 분할 테스트용). */
    private function productWithIp(string $code, string $title, ?string $makerCode, int $shopId, string $extId, float $price, ?int $ipId): OtakuProduct
    {
        $p = OtakuProduct::create([
            'ok_product_code' => $code,
            'ok_product_title' => $title,
            'ok_product_maker_code' => $makerCode,
            'ok_product_active_flg' => true,
            'ok_product_cate_id' => $this->cateId,
            'ok_product_ip_id' => $ipId,
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

    public function test_rematch_does_not_merge_same_maker_number_across_different_ips(): void
    {
        $mikuIpId = OtakuIp::create(['ok_ip_code' => '하츠네미쿠', 'ok_ip_label' => '하츠네미쿠', 'ok_ip_sort' => 2])->ok_ip_id;
        $nikkeIpId = OtakuIp::create(['ok_ip_code' => '승리의여신니케', 'ok_ip_label' => '승리의여신니케', 'ok_ip_sort' => 3])->ok_ip_id;

        // 제조사가 달라 넨도 번호(No.3057)가 겹치는 다른 작품의 상품들 — 품번이 같아도 병합 금지.
        $this->productWithIp('p1', '[보컬로이드] 넨도로이드 No.3057 캐릭터 보컬 시리즈 01: 하츠네 미쿠 안경×카페 Ver.', 'nendo_3057', $this->shopA, 'A1', 70000, $mikuIpId);
        $this->productWithIp('p2', '[승리의 여신 니케] 넨도로이드 No.3057 라피: 레드 후드 Ver.', 'nendo_3057', $this->shopB, 'B1', 72000, $nikkeIpId);
        // IP가 2종 이상인 그룹에선 IP 미추출(null) 행도 어느 작품인지 알 수 없으므로 병합 제외.
        $this->productWithIp('p3', '넨도로이드 No.3057 캐릭터 상품', 'nendo_3057', $this->shopB, 'B2', 71000, null);

        $this->artisan('otaku-shop:rematch')->assertSuccessful();

        $this->assertSame(3, OtakuProduct::count(), '품번이 같아도 IP가 다르거나 불명이면 병합하지 않아야 함');
    }

    public function test_rematch_null_ip_joins_group_with_single_ip(): void
    {
        // 같은 품번 그룹에 non-null IP가 1종뿐이면, IP 미추출(null) 행도 그쪽에 합류해 병합된다(분리 회귀 방지).
        $this->product('p1', '[리코리스 리코일] 넨도로이드 넘버1955 니시키기 치사토', 'nendo_1955', $this->shopA, 'A1', 63000);
        $this->productWithIp('p2', '넨도로이드 No.1955 니시키기 치사토', null, $this->shopB, 'B1', 65000, null);

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

    public function test_cleanup_excluded_deletes_split_payment_products(): void
    {
        config(['otaku-crawler.exclude_title_keywords' => ['잔금결제', '예약금결제']]);

        $keep = $this->product('p1', '후지타 코토네 Re;IRIS 비 갠 뒤의 아이리스 Ver. 1/7', null, $this->shopA, 'A1', 250000);
        $this->product('p2', '후지타 코토네 Re;IRIS 비 갠 뒤의 아이리스 Ver. 1/7 (예약금결제)', null, $this->shopB, 'B1', 50000);
        $this->product('p3', '원신 유라 1/7 피규어 (잔금결제)', null, $this->shopB, 'B2', 180000);

        $this->artisan('otaku-shop:rematch --cleanup-excluded')->assertSuccessful();

        // 예약금/잔금결제 상품과 그 오퍼만 삭제, 정상 상품은 유지.
        $this->assertSame(1, OtakuProduct::count());
        $this->assertSame($keep->ok_product_id, OtakuProduct::first()->ok_product_id);
        $this->assertSame(1, OtakuOffer::count());
    }

    public function test_cleanup_excluded_dry_run_keeps_products(): void
    {
        config(['otaku-crawler.exclude_title_keywords' => ['예약금결제']]);

        $this->product('p1', '후지타 코토네 1/7', null, $this->shopA, 'A1', 250000);
        $this->product('p2', '후지타 코토네 1/7 (예약금결제)', null, $this->shopB, 'B1', 50000);

        $this->artisan('otaku-shop:rematch --cleanup-excluded --dry-run')->assertSuccessful();

        $this->assertSame(2, OtakuProduct::count());
        $this->assertSame(2, OtakuOffer::count());
    }
}
