<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 크롤러 증분 동기화용: 샵별 외부 상품 식별자 (중복/업데이트 판단).
     */
    public function up(): void
    {
        Schema::table('otaku_offer', function (Blueprint $table) {
            $table->string('ok_offer_external_id', 255)->nullable()->after('ok_offer_shop_id')
                ->comment('샵 내부 상품 ID 또는 URL 해시 (증분 크롤 시 매칭용)');
            $table->index(['ok_offer_shop_id', 'ok_offer_external_id'], 'idx_ok_of_shop_external');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_offer', function (Blueprint $table) {
            $table->dropIndex('idx_ok_of_shop_external');
            $table->dropColumn('ok_offer_external_id');
        });
    }
};
