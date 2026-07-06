<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 캐릭터 이미지 로컬 캐시 경로 추가.
 * 외부 이미지(개인 팬사이트 CDN)를 핫링크로 상시 노출하는 대신 public 디스크에 1회
 * 다운로드해 서빙한다 — 원본이 죽어도 화면이 안 깨지고, 원 사이트 트래픽 부담도 없다.
 * image_url(원본)은 출처·재다운로드 판단용으로 유지한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subculture_characters', function (Blueprint $table) {
            $table->string('image_path', 300)->nullable()->after('image_url')
                ->comment('public 디스크 내 캐시 경로(없으면 image_url 폴백)');
        });
    }

    public function down(): void
    {
        Schema::table('subculture_characters', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
