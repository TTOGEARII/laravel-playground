<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->string('short_intro', 50)->comment('한 줄 소개')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->string('short_intro', 30)->comment('한 줄 소개')->change();
        });
    }
};
