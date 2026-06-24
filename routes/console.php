<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 오타쿠샵 크롤링 (3·4번 증분만 매일 실행)
|--------------------------------------------------------------------------
| [Sail + compose.yaml] Selenium 서비스는 compose.yaml 에 정의되어 있습니다.
|   1) 컨테이너 실행:   ./vendor/bin/sail up -d
|   2) 전체 크롤:       ./vendor/bin/sail artisan otaku-shop:crawl
|   3) 증분 크롤:       ./vendor/bin/sail artisan otaku-shop:crawl --incremental
|   4) 스케줄 실행:     ./vendor/bin/sail artisan schedule:work (백그라운드)
|      또는 호스트 cron: * * * * * cd /path/to/project && ./vendor/bin/sail artisan schedule:run
| Selenium 엔드포인트는 .env 의 OTAKU_SELENIUM_URL (기본: http://selenium:4444)을 따릅니다.
*/
// 매일 03:00 증분 크롤 (가격/재고 갱신) — 단, 일요일(0)은 제외한다.
// 일요일 04:00 전량 크롤과 시간이 가까워, 증분이 1시간 넘게 돌면 둘이 겹쳐
// 단일 Selenium 세션을 두고 경합 → 세션 타임아웃으로 크롤이 실패한다.
// withoutOverlapping 은 같은 명령어끼리만 막아주므로(증분↔전량은 못 막음) 요일로 분리한다.
Schedule::command('otaku-shop:crawl --incremental')
    ->dailyAt('03:00')
    ->days([1, 2, 3, 4, 5, 6]) // 월~토 (일요일 제외)
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(120)
    ->runInBackground();

// 매주 일요일 04:00 전량 크롤 (모든 카테고리 재수집 + 사라짐 기반 품절 정합성 보정)
// crawl-full 은 카테고리 전체를 끝 페이지까지 돌아, 이번에 안 보인 오퍼를 품절 처리한다.
// (품절을 리스트에 안 띄우는 쇼핑몰의 품절을 이 주간 정합성 보정이 잡는다.)
Schedule::command('otaku-shop:crawl-full --yes')
    ->weeklyOn(0, '04:00')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(360)
    ->runInBackground();
