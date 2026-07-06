<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 편성 구성원(파티 ↔ 캐릭터). slot_type 으로 게임별 슬롯 구분
 * (블아: striker/special, 니케: 버스트 순번 등)을 표현한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_raid_party_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subculture_raid_party_id')->constrained('subculture_raid_parties')->cascadeOnDelete();
            $table->foreignId('subculture_character_id')->constrained('subculture_characters')->cascadeOnDelete();
            $table->string('slot_type', 20)->nullable()->comment('슬롯 구분(striker/special, 버스트 순번 등)');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('note', 120)->nullable()->comment('대체 가능/필수 여부 등 메모');
            $table->timestamps();

            $table->unique(['subculture_raid_party_id', 'subculture_character_id'], 'uniq_srpm_party_char');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_raid_party_members');
    }
};
