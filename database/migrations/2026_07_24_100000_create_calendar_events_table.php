<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 행사 캘린더: J-pop 내한공연 + 서브컬쳐 오프라인 행사(코믹월드·일러스타페스·AGF 등)를
 * 소스별로 수집해 한 테이블에 통합한다. (source, external_key)가 동일성 키.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30)->comment('수집 소스(festivallife/comicworld/illustar/manual)');
            $table->string('external_key', 150)->comment('소스 내 동일성 키(게시글 idx/회차번호 등)');
            $table->string('kind', 20)->comment('행사 종류(concert=공연, doujin=동인행사, expo=기업행사)');
            $table->string('genre', 20)->nullable()->comment('공연 장르 태그(jpop/other, Gemini 분류 — 행사류는 null)');
            $table->string('title', 300)->comment('행사/공연명');
            $table->date('starts_on')->comment('시작일');
            $table->date('ends_on')->nullable()->comment('종료일(당일 행사는 null)');
            $table->string('time_text', 100)->nullable()->comment('시각 원문(예: 오후 8시 — 파싱 대신 원문 보존)');
            $table->string('venue', 200)->nullable()->comment('장소');
            $table->text('price_text')->nullable()->comment('가격 원문(좌석별 나열)');
            $table->string('ticket_open_text', 200)->nullable()->comment('티켓 오픈 일정 원문');
            $table->json('ticket_links')->nullable()->comment('예매 링크 [{label,url}]');
            $table->json('extra')->nullable()->comment('소스별 부가 정보(예매처 텍스트·부스 안내 링크 등)');
            $table->string('poster_url', 500)->nullable()->comment('포스터 원본 URL');
            $table->string('poster_path', 300)->nullable()->comment('포스터 storage 캐시 경로');
            $table->string('detail_url', 500)->nullable()->comment('원문(공식/게시글) 링크');
            $table->boolean('active_flg')->default(true)->comment('노출 여부');
            $table->timestamps();

            $table->unique(['source', 'external_key']);
            $table->index('starts_on');
            $table->index(['kind', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
