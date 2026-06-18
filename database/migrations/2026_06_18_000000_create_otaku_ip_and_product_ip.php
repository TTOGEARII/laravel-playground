<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IP(작품) 분류 테이블과 상품→IP FK 추가.
     * 상품종류(otaku_category)와 동일한 패턴의 분류 축을 하나 더 둔다.
     */
    public function up(): void
    {
        Schema::create('otaku_ip', function (Blueprint $table) {
            $table->bigIncrements('ok_ip_id')->comment('IP PK ID');
            $table->string('ok_ip_code', 80)->comment('IP 코드(정규화 표준 토큰, 내부 식별용)');
            $table->string('ok_ip_label', 120)->comment('IP 표시 이름');
            $table->integer('ok_ip_sort')->default(0)->comment('정렬 순서');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시');

            $table->unique('ok_ip_code', 'idx_ok_ip_code');
            $table->index('ok_ip_sort', 'idx_ok_ip_sort');

            $table->comment('Otaku Shop - IP(작품) 분류 테이블');
        });

        Schema::table('otaku_product', function (Blueprint $table) {
            $table->unsignedBigInteger('ok_product_ip_id')->nullable()->after('ok_product_cate_id')
                ->comment('IP(작품) ID (otaku_ip)');
            $table->index('ok_product_ip_id', 'idx_ok_pr_ip');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->dropIndex('idx_ok_pr_ip');
            $table->dropColumn('ok_product_ip_id');
        });

        Schema::dropIfExists('otaku_ip');
    }
};
