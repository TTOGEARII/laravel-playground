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
        Schema::create('otaku_shop', function (Blueprint $table) {
            $table->bigIncrements('ok_shop_id')->comment('샵 PK ID');
            $table->string('ok_shop_code', 50)->comment('샵 코드(내부 식별용)');
            $table->string('ok_shop_name', 150)->comment('샵 이름(표시용)');
            $table->string('ok_shop_url', 255)->nullable()->comment('샵 기본 URL');
            $table->boolean('ok_shop_active_flg')->default(true)->comment('사용 여부(1:사용,0:미사용)');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시');

            $table->unique('ok_shop_code', 'idx_ok_sh_code');
            $table->index('ok_shop_active_flg', 'idx_ok_sh_active');

            $table->comment('Otaku Shop - 판매처/샵 정보 테이블');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otaku_shop');
    }
};

