<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 문의(1:1 문의/버그 제보/기능 요청) 보관 테이블.
 * 누구나(비로그인 포함) 남길 수 있고, 로그인 사용자는 user_id 로 연결한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20)->default('general')->comment('문의 유형: general/bug/feature');
            $table->string('name', 50)->comment('작성자 이름/닉네임');
            $table->string('contact', 120)->nullable()->comment('답변받을 연락처(이메일/디스코드 등, 선택)');
            $table->string('subject', 120)->comment('제목');
            $table->text('message')->comment('문의 내용');
            $table->string('status', 20)->default('received')->comment('처리 상태: received/in_progress/resolved');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('로그인 사용자면 연결');
            $table->string('ip_address', 45)->nullable()->comment('스팸 차단용(IPv6 대응)');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
