<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('affinity')->default(10)->comment('호감도 게이지 0~100')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('affinity')->default(30)->comment('호감도 게이지 0~100')->change();
        });
    }
};
