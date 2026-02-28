<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->text('greeting_message')->nullable()->after('speech_style')->comment('캐릭터 첫 인사 (Gemini 생성)');
        });
    }

    public function down(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->dropColumn('greeting_message');
        });
    }
};
