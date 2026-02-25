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
        Schema::table('otaku_product', function (Blueprint $table) {
            if (! Schema::hasColumn('otaku_product', 'ok_product_cate_id')) {
                $table->unsignedBigInteger('ok_product_cate_id')->nullable()->after('ok_product_active_flg')
                    ->comment('카테고리 ID (otaku_category.ok_category_id)');
                $table->index('ok_product_cate_id', 'idx_ok_pr_cate_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            if (Schema::hasColumn('otaku_product', 'ok_product_cate_id')) {
                $table->dropIndex('idx_ok_pr_cate_id');
                $table->dropColumn('ok_product_cate_id');
            }
        });
    }
};
