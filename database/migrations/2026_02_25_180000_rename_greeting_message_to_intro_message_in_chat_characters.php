<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_characters', 'greeting_message')) {
            Schema::table('chat_characters', function (Blueprint $table) {
                $table->renameColumn('greeting_message', 'intro_message');
            });
        } elseif (! Schema::hasColumn('chat_characters', 'intro_message')) {
            Schema::table('chat_characters', function (Blueprint $table) {
                $table->text('intro_message')->nullable()->after('speech_style')->comment('캐릭터 인트로 (대화 첫 메시지)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_characters', 'intro_message')) {
            Schema::table('chat_characters', function (Blueprint $table) {
                $table->renameColumn('intro_message', 'greeting_message');
            });
        }
    }
};
