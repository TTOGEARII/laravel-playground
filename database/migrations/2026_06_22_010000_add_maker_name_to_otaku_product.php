<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 제조사명 컬럼 추가. 상세 페이지 크롤(fetch_detail) 시 보강한다.
     * 표시·그룹핑 보조용이며, 동일상품 매칭의 주 키는 ok_product_maker_code(바코드)다.
     */
    public function up(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->string('ok_product_maker_name', 120)->nullable()->after('ok_product_maker_code')
                ->comment('제조사명(상세 크롤로 보강) — 예: 굿스마일 컴퍼니');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->dropColumn('ok_product_maker_name');
        });
    }
};
