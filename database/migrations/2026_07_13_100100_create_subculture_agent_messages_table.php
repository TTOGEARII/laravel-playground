<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 에이전트 대화 메시지. assistant 메시지는 툴 호출 기록(tool_calls)과
 * 구조화 카드(cards, 프론트 렌더용)를 함께 저장한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subculture_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('subculture_agent_sessions')->cascadeOnDelete();
            $table->string('role', 20)->comment('user|assistant');
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable()->comment('[{name, args}] 진행 표시·감사용');
            $table->json('cards')->nullable()->comment('[{type, data}] 구조화 카드');
            $table->timestamps();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subculture_agent_messages');
    }
};
