<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('otaku_offer', function (Blueprint $table) {
            $table->bigIncrements('ok_offer_id')->comment('오퍼 PK ID');
            $table->unsignedBigInteger('ok_offer_product_id')->comment('상품 ID (otaku_product.ok_product_id)');
            $table->unsignedBigInteger('ok_offer_shop_id')->comment('샵 ID (otaku_shop.ok_shop_id)');
            $table->char('ok_offer_currency', 3)->comment('통화 코드(JPY,KRW 등)');
            $table->decimal('ok_offer_price', 12, 2)->comment('기준 통화 가격');
            $table->decimal('ok_offer_local_price', 12, 2)->nullable()->comment('환산된 로컬 통화 가격');
            $table->decimal('ok_offer_shipping_fee', 12, 2)->nullable()->comment('배송비');
            $table->boolean('ok_offer_lowest_flg')->default(false)->comment('최저가 여부 플래그');
            $table->boolean('ok_offer_available_flg')->default(true)->comment('판매 가능 여부');
            $table->string('ok_offer_external_url', 512)->nullable()->comment('외부 샵 상품 상세 URL');
            $table->dateTime('ok_offer_collected_dt')->nullable()->comment('가격 수집/동기화 시각');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시');

            // FK 는 걸지 않고, 조회용 인덱스만 설정
            $table->index('ok_offer_product_id', 'idx_ok_of_product');
            $table->index('ok_offer_shop_id', 'idx_ok_of_shop');
            $table->index(['ok_offer_product_id', 'ok_offer_shop_id'], 'idx_ok_of_product_shop');
            $table->index(['ok_offer_product_id', 'ok_offer_lowest_flg'], 'idx_ok_of_lowest');
            $table->index('ok_offer_available_flg', 'idx_ok_of_available');

            $table->comment('Otaku Shop - 상품별 샵 오퍼(가격/재고/링크) 정보 테이블');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otaku_offer');
    }
};

