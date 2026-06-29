<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 게임별 리딤/쿠폰 코드.
 * 동일성 키는 (게임, 리전, 코드). 같은 코드라도 리전(글로벌/아시아/KR 등)별로 분리 관리한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redeem_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_game_id')->constrained('subculture_games')->cascadeOnDelete();
            $table->string('code', 64)->comment('코드 문자열(대소문자 보존)');
            $table->string('region', 16)->default('global')->comment('global/asia/kr/jp/cn');
            $table->string('rewards', 255)->nullable()->comment('보상 설명(텍스트)');
            $table->string('source', 40)->comment('수집 소스 키(ennead, mollulog, dc ...)');
            $table->string('source_type', 20)->default('aggregator')->comment('aggregator/community');
            $table->string('source_url', 255)->nullable()->comment('출처 URL');
            $table->string('status', 16)->default('unverified')->comment('unverified/active/expired');
            $table->timestamp('found_at')->nullable()->comment('최초 수집 시각');
            $table->timestamp('expires_at')->nullable()->comment('만료 시각(파악된 경우)');
            $table->timestamp('verified_at')->nullable()->comment('유효성 확인 시각');
            $table->timestamps();

            $table->unique(['subculture_game_id', 'region', 'code'], 'uniq_rc_game_region_code');
            $table->index(['subculture_game_id', 'status'], 'idx_rc_game_status');
            $table->index('source_type', 'idx_rc_source_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redeem_codes');
    }
};
