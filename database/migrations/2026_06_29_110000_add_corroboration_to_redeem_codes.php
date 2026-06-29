<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 교차검증(여러 출처에서 같은 코드를 봤는지)으로 정확도를 높이기 위한 컬럼.
 * - seen_sources: 이 코드를 보고한 출처 키 목록(JSON)
 * - corroboration_count: 서로 다른 출처 수(많을수록 신뢰)
 * - last_seen_at: 마지막으로 수집에서 관측된 시각
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redeem_codes', function (Blueprint $table) {
            $table->json('seen_sources')->nullable()->after('source_url');
            $table->unsignedSmallInteger('corroboration_count')->default(1)->after('seen_sources');
            $table->timestamp('last_seen_at')->nullable()->after('found_at');
            $table->index(['subculture_game_id', 'corroboration_count'], 'idx_rc_game_corrob');
        });
    }

    public function down(): void
    {
        Schema::table('redeem_codes', function (Blueprint $table) {
            $table->dropIndex('idx_rc_game_corrob');
            $table->dropColumn(['seen_sources', 'corroboration_count', 'last_seen_at']);
        });
    }
};
