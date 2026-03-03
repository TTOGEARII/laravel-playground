<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chat_* 테이블에 회원 식별용 user_id 추가 (foreign key 없이 index만).
     */
    public function up(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id')->comment('캐릭터 생성 회원 ID');
            $table->index('user_id');
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id')->comment('대화 세션 회원 ID');
            $table->index('user_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id')->comment('메시지 소유 회원 ID (세션 기준)');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_characters', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
