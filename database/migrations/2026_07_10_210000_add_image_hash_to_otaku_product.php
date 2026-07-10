<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 이미지 지각 해시(dHash 64bit, hex 16자) — 이미지 확증 병합용.
 * 리매치가 경계 후보 쌍에 대해서만 지연 계산해 저장한다(전량 백필 없음).
 * 빈 문자열('')은 "다운로드/해시 실패 — 재시도 안 함" 마커.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->string('ok_product_image_hash', 16)->nullable()->after('ok_product_image_url')
                ->comment('이미지 dHash(hex16) — 빈값=해시 실패');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_product', function (Blueprint $table) {
            $table->dropColumn('ok_product_image_hash');
        });
    }
};
