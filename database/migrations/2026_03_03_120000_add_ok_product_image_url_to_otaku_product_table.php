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
            if (! Schema::hasColumn('otaku_product', 'ok_product_image_url')) {
                $table->string('ok_product_image_url', 500)
                    ->nullable()
                    ->after('ok_product_cate_id')
                    ->comment('대표 상품 이미지 URL');

                $table->index('ok_product_image_url', 'idx_ok_pr_image_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            if (Schema::hasColumn('otaku_product', 'ok_product_image_url')) {
                $table->dropIndex('idx_ok_pr_image_url');
                $table->dropColumn('ok_product_image_url');
            }
        });
    }
};

