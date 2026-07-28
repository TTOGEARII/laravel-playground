<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            // 구독별 알림 주제 선택(리딤코드 redeem / 내한공연 concert / 행사 event).
            // null = 전체 수신(기존 구독자 하위호환 — 지금까지의 동작 그대로).
            $table->json('topics')->nullable()->after('endpoint_hash');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('topics');
        });
    }
};
