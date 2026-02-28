<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_characters', 'speech_style')) {
            return;
        }
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->text('speech_style')->nullable()->after('character_detail')->comment('말투 설정');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chat_characters', 'speech_style')) {
            return;
        }
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->dropColumn('speech_style');
        });
    }
};
