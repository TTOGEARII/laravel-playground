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
        Schema::create('otaku_category', function (Blueprint $table) {
            $table->bigIncrements('ok_category_id')->comment('카테고리 PK ID');
            $table->string('ok_category_code', 50)->comment('카테고리 코드(내부 식별용)');
            $table->string('ok_category_label', 100)->comment('카테고리 표시 이름');
            $table->integer('ok_category_sort')->default(0)->comment('정렬 순서');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시');

            $table->unique('ok_category_code', 'idx_ok_ct_code');
            $table->index('ok_category_sort', 'idx_ok_ct_sort');

            $table->comment('Otaku Shop - 상품 카테고리 테이블');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otaku_category');
    }
};

