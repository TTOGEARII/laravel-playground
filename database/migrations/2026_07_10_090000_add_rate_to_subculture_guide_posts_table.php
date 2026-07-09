<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 공략글 추천수 저장 — 피드를 '추천수 많은 순'으로 정렬하기 위함.
 * (드라이버는 이미 추천수를 파싱하고 있었고 저장만 안 하고 있었다)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subculture_guide_posts', function (Blueprint $table) {
            $table->unsignedInteger('rate')->default(0)->after('views')->comment('추천 수');
        });
    }

    public function down(): void
    {
        Schema::table('subculture_guide_posts', function (Blueprint $table) {
            $table->dropColumn('rate');
        });
    }
};
