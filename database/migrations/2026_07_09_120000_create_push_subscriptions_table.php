<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 웹푸시 구독 — 브라우저(기기) 단위. 로그인 여부와 무관하게 구독 가능해 user_id 는 nullable.
 * endpoint 가 구독의 고유 식별자(같은 기기 재구독 시 갱신).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 500);
            $table->string('p256dh_key');
            $table->string('auth_key');
            $table->timestamps();

            // endpoint 는 500자라 그대로 unique 를 걸면 인덱스 길이 제한에 걸린다 → 해시 컬럼으로
            $table->string('endpoint_hash', 64)->unique()->comment('sha256(endpoint)');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
