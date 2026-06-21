<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 상품 고유값(제조사 품번/JAN 바코드 등)을 저장할 컬럼 추가.
     * 동일 상품을 쇼핑몰 간 매칭할 때 제목 정규화보다 우선하는 강한 키로 쓴다.
     */
    public function up(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->string('ok_product_maker_code', 64)->nullable()->after('ok_product_brand_label')
                ->comment('상품 고유값(JAN 바코드/제조사 품번 등) — 쇼핑몰 간 동일상품 매칭 키');

            $table->index('ok_product_maker_code', 'idx_ok_pr_maker_code');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->dropIndex('idx_ok_pr_maker_code');
            $table->dropColumn('ok_product_maker_code');
        });
    }
};
