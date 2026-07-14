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

/*
|--------------------------------------------------------------------------
| 서브컬쳐 게임 리딤코드 수집 (subculture:collect)
|--------------------------------------------------------------------------
| 호요버스 API(원신/스타레일/젠레스) + 정리 사이트(블아/명조/트릭컬) + 커뮤니티(보조).
| 라이브 코드는 24~48h로 휘발성이 커, 평상시 하루 3회 정도로 자주 수집한다.
| (버전 특별방송 시점엔 수동으로 더 자주 돌리는 것을 권장)
*/
Schedule::command('subculture:collect')
    ->cron('0 9,15,21 * * *') // 매일 09/15/21시
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(20)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| 서브컬쳐 게임 레이드 정보 (Playwright 사이드카 + 커뮤니티 공략글)
|--------------------------------------------------------------------------
| 캐릭터 마스터는 신캐 추가 주기가 낮아 주 1회, 레이드 일정·편성은 매일 1회,
| 공략글 메타(가벼운 HTTP)는 하루 2회 수집한다.
| 오타쿠샵(03~04시)·리딤코드(09/15/21시)와 시간대를 분리했다.
| 사전 준비(브라우저 설치): PLAYWRIGHT_BROWSERS_PATH=storage/app/playwright 로
|   ./vendor/bin/sail npm exec playwright install chromium
*/
Schedule::command('subculture:crawl-characters')
    ->weeklyOn(1, '05:00') // 매주 월요일 05:00
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('subculture:crawl-raids')
    ->dailyAt('05:30')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 블아 SchaleDB 동기화 — 캐릭터정보(도감) 필드 보강(정적 JSON, Gemini 없음). 매일 1회.
Schedule::command('subculture:sync-schaledb')
    ->dailyAt('05:40')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 블아 몰루로그 미래시 동기화 — KR 픽업(복각)·이벤트(배너 이미지)·레이드 일정(장갑별 난이도). 매일 2회(픽업 교체 대응).
Schedule::command('subculture:sync-mollulog-futures')
    ->twiceDaily(5, 17)
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 호요버스 Yatta 동기화 — 캐릭터 도감(학정보, 원신·스타레일). 정적 JSON, Gemini 없음. 매일 1회.
Schedule::command('subculture:sync-yatta')
    ->dailyAt('05:45')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 젠레스 Enka 동기화 — 에이전트 도감(학정보). 정적 JSON(GitHub raw), Gemini 없음. 매일 1회.
Schedule::command('subculture:sync-zenless')
    ->dailyAt('05:50')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 게임 위키 동기화 — 호요랩 위키(젠존제·스타레일 전 카테고리)+wuthering.gg(명조), 항목별 상세 포함(신규만). 매주 화 06:30.
Schedule::command('subculture:sync-wiki')
    ->weeklyOn(2, '06:30')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(60)
    ->runInBackground();

// 호요버스 빌드 보강 — 티어(젠존제 zzz.gg)·추천 조합 영상(유튜브)·성장재료(Yatta). 매주 화 07:00.
Schedule::command('subculture:sync-hoyo-build')
    ->weeklyOn(2, '07:00')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(60)
    ->runInBackground();

// 호요버스 추천 무기·세트 — genshin-builds.com(한국어). 매주 화 07:30.
Schedule::command('subculture:sync-genshin-builds')
    ->weeklyOn(2, '07:30')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(60)
    ->runInBackground();

// 블아 종합전술시험(종전시) — 새 차수 글만 가볍게 확인(아카 HTML 파싱, Gemini 없음). 매일 1회.
Schedule::command('subculture:collect-jfd')
    ->dailyAt('06:10')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 블아 이벤트 챌린지 — 아카 '올인원' 글에서 챌린지 조합·영상 파싱(가벼운 HTML, Gemini 없음). 매일 1회.
Schedule::command('subculture:collect-event-challenges')
    ->dailyAt('06:40')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// 속성(성격)별 추천 조합(트릭컬) — 큐레이션은 변동이 느리지만 실측(시즌 집계)이 주 단위로 갱신되어 주 1회.
Schedule::command('subculture:crawl-attribute-parties')
    ->weeklyOn(1, '06:00') // 매주 월요일 06:00 (캐릭터 마스터 05:00 이후)
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('subculture:collect-guides')
    ->cron('0 8,20 * * *') // 매일 08/20시
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(15)
    ->runInBackground();

// 공략글 수집(08시) 이후 본문에서 대체 캐릭터 관계 추출(Gemini 호출 비용이 있어 하루 1회).
Schedule::command('subculture:extract-substitutes')
    ->dailyAt('09:30')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->withoutOverlapping(30)
    ->runInBackground();

// database 캐시 드라이버는 만료 행을 스스로 지우지 않는다 — 유저별 캐시 키(실전 편성 등)가
// 쌓여 cache 테이블이 계속 부풀지 않도록 만료분을 매일 정리한다.
Schedule::call(fn () => \Illuminate\Support\Facades\DB::table('cache')
    ->where('expiration', '<', now()->getTimestamp())
    ->delete())
    ->name('cache-prune-expired')
    ->dailyAt('04:40')
    ->timezone(config('app.timezone', 'Asia/Seoul'))
    ->when(fn () => config('cache.default') === 'database');
