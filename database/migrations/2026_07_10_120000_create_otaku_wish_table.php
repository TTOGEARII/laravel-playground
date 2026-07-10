<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 오타쿠샵 찜 — 로그인 사용자 전용. 품절 상품이 재입고되면 웹푸시로 알린다.
 * (OtakuShop 도메인 컨벤션: ok_ prefix + create_dt/update_dt)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otaku_wish', function (Blueprint $table) {
            $table->bigIncrements('ok_wish_id')->comment('찜 PK ID');
            $table->unsignedBigInteger('user_id')->comment('회원 ID');
            $table->unsignedBigInteger('ok_wish_product_id')->comment('상품 ID');
            $table->dateTime('create_dt')->nullable()->comment('생성 일시');
            $table->dateTime('update_dt')->nullable()->comment('수정 일시');

            $table->unique(['user_id', 'ok_wish_product_id']);
            $table->index('ok_wish_product_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('ok_wish_product_id')->references('ok_product_id')->on('otaku_product')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otaku_wish');
    }
};
