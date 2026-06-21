<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->text('personality')->nullable()->after('character_detail')->comment('성격 (페르소나)');
            $table->text('appearance')->nullable()->after('personality')->comment('외모 묘사');
            $table->string('likes', 255)->nullable()->after('appearance')->comment('좋아하는 것');
            $table->string('dislikes', 255)->nullable()->after('likes')->comment('싫어하는 것');
            $table->string('user_alias', 50)->nullable()->after('dislikes')->comment('캐릭터가 유저를 부르는 호칭');
            $table->text('example_dialogue')->nullable()->after('user_alias')->comment('예시 대화 (few-shot)');
            $table->text('world_setting')->nullable()->after('example_dialogue')->comment('소설/세계관 배경 설정');
        });
    }

    public function down(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->dropColumn(['personality', 'appearance', 'likes', 'dislikes', 'user_alias', 'example_dialogue', 'world_setting']);
        });
    }
};
