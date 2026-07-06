<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 레이드별 추천 편성(파티). 크롤 소스 파티는 레이드 단위로 갈아끼우고
 * source='manual' 파티는 sync 시에도 보존한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_raid_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_raid_id')->constrained('subculture_raids')->cascadeOnDelete();
            $table->string('title', 80)->nullable()->comment('편성 이름(예: 1파티 딜링, 무과금 편성)');
            $table->string('difficulty', 40)->nullable()->comment('난이도(TORMENT/INSANE, 헬 단계 등)');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('source', 40)->comment('크롤 소스 키 또는 manual');
            $table->string('source_url', 500)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('subculture_raid_id', 'idx_srp_raid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_raid_parties');
    }
};
