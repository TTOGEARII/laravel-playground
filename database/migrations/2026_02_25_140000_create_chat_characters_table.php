<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_characters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->comment('캐릭터 이름');
            $table->string('short_intro', 50)->comment('한 줄 소개');
            $table->string('character_detail', 1000)->nullable()->comment('캐릭터 상세');
            $table->string('genre', 30)->default('romance')->comment('장르: romance,fantasy,action,slice_of_life,otaku');
            $table->string('target', 20)->default('all')->comment('타겟: all,male,female,teen');
            $table->string('image_path', 255)->nullable()->comment('캐릭터 이미지 경로 (storage 기준)');
            $table->string('accent', 30)->default('accent-violet')->comment('카드 강조색 클래스');
            $table->timestamps();

            $table->index('genre');
            $table->index('target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_characters');
    }
};
