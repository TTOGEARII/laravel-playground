<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->string('ok_product_match_sig', 255)->nullable()->after('ok_product_maker_name')
                ->comment('이름 유사 매칭용 정규화 시그니처(정렬 토큰)');
            $table->index(['ok_product_ip_id', 'ok_product_cate_id'], 'idx_product_ip_cate');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->dropIndex('idx_product_ip_cate');
            $table->dropColumn('ok_product_match_sig');
        });
    }
};
