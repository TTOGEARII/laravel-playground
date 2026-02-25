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
        Schema::create('otaku_product', function (Blueprint $table) {
            $table->bigIncrements('ok_product_id')->comment('상품 PK ID');
            $table->string('ok_product_code', 50)->comment('상품 코드(내부 식별용)');
            $table->string('ok_product_title', 255)->comment('상품 제목');
            $table->string('ok_product_subtitle', 255)->nullable()->comment('상품 서브 제목/설명');
            $table->string('ok_product_brand_label', 120)->nullable()->comment('브랜드/레이블 이름');
            $table->date('ok_product_release_date')->nullable()->comment('발매일');
            $table->boolean('ok_product_active_flg')->default(true)->comment('노출 여부(1:노출,0:숨김)');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시');

            $table->unique('ok_product_code', 'idx_ok_pr_code');
            $table->index('ok_product_title', 'idx_ok_pr_title');
            $table->index('ok_product_brand_label', 'idx_ok_pr_brand');
            $table->index('ok_product_release_date', 'idx_ok_pr_release');
            $table->index('ok_product_active_flg', 'idx_ok_pr_active');

            $table->comment('Otaku Shop - 비교 대상 상품 정보 테이블');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otaku_product');
    }
};

