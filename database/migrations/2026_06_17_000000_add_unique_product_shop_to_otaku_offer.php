<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 가격비교 정합성: 한 상품에 대해 쇼핑몰별 오퍼는 1건이어야 한다.
 * (같은 샵의 일반/특전 등 변형이 동일 상품으로 묶이며 생기던 중복 오퍼 제거 후 유니크 인덱스 부여)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) (상품, 샵) 중복 오퍼 정리 — 최저가(동가면 가장 작은 id) 1건만 남긴다.
        $dups = DB::table('otaku_offer')
            ->select('ok_offer_product_id', 'ok_offer_shop_id')
            ->groupBy('ok_offer_product_id', 'ok_offer_shop_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dups as $dup) {
            $keepId = DB::table('otaku_offer')
                ->where('ok_offer_product_id', $dup->ok_offer_product_id)
                ->where('ok_offer_shop_id', $dup->ok_offer_shop_id)
                ->orderBy('ok_offer_price')
                ->orderBy('ok_offer_id')
                ->value('ok_offer_id');

            DB::table('otaku_offer')
                ->where('ok_offer_product_id', $dup->ok_offer_product_id)
                ->where('ok_offer_shop_id', $dup->ok_offer_shop_id)
                ->where('ok_offer_id', '!=', $keepId)
                ->delete();
        }

        // 2) (상품, 샵) 유니크 인덱스
        Schema::table('otaku_offer', function (Blueprint $table) {
            $table->unique(['ok_offer_product_id', 'ok_offer_shop_id'], 'uniq_ok_of_product_shop');
        });
    }

    public function down(): void
    {
        Schema::table('otaku_offer', function (Blueprint $table) {
            $table->dropUnique('uniq_ok_of_product_shop');
        });
    }
};
