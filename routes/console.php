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
Schedule::command('otaku-shop:crawl --incremental')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(120)
    ->runInBackground();
