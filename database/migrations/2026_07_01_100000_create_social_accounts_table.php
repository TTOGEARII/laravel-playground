<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 소셜(카카오/구글) 가입 사용자는 비밀번호가 없고, 제공자가 이메일을 안 줄 수도 있어 두 컬럼을 nullable 로 완화.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change();
        });

        // 한 사용자가 여러 제공자를 연결할 수 있도록 별도 테이블로 분리(제공자 토큰 저장 포함).
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);              // kakao / google
            $table->string('provider_user_id');          // 제공자 측 고유 회원 ID
            $table->string('nickname')->nullable();
            $table->string('profile_image', 500)->nullable();
            $table->text('access_token')->nullable();     // 제공자 API 호출용(예: 카카오톡 메시지)
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            // (제공자, 제공자 회원ID) 조합은 유일 — 같은 소셜 계정이 중복 연결되지 않도록.
            $table->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
