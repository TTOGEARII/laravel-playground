<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 오타쿠샵 해외관: 샵에 지역/통화 속성 추가 + 환율 테이블 신설.
 * 상품은 국내/해외 공통이고 지역은 샵(오퍼)의 속성이다 — 해외관 = 해외 샵 오퍼를 가진 상품.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otaku_shop', function (Blueprint $table) {
            $table->string('ok_shop_region', 10)->default('kr')->after('ok_shop_url')
                ->comment('샵 지역(kr:국내, global:해외)');
            $table->string('ok_shop_currency', 3)->default('KRW')->after('ok_shop_region')
                ->comment('샵 표시 통화(KRW/JPY/USD)');
            $table->index('ok_shop_region', 'idx_ok_sh_region');
        });

        Schema::create('otaku_exchange_rate', function (Blueprint $table) {
            $table->bigIncrements('ok_rate_id')->comment('환율 PK ID');
            $table->string('ok_rate_currency', 3)->comment('통화 코드(JPY/USD 등)');
            $table->decimal('ok_rate_krw', 14, 6)->comment('1 통화당 원화 환산율');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시(마지막 환율 갱신)');

            $table->unique('ok_rate_currency', 'idx_ok_rate_currency');

            $table->comment('Otaku Exchange Rate - 해외 샵 가격 원화 환산용 환율');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_shop', function (Blueprint $table) {
            $table->dropIndex('idx_ok_sh_region');
            $table->dropColumn(['ok_shop_region', 'ok_shop_currency']);
        });
        Schema::dropIfExists('otaku_exchange_rate');
    }
};
