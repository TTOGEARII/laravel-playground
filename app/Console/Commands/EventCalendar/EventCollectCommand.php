<?php

namespace App\Console\Commands\EventCalendar;

use App\Services\EventCalendar\EventSyncService;
use App\Services\EventCalendar\GenreTagService;
use App\Services\EventCalendar\Sources\ComicWorldDriver;
use App\Services\EventCalendar\Sources\Contracts\EventSource;
use App\Services\EventCalendar\Sources\FestivalLifeDriver;
use App\Services\EventCalendar\Sources\IllustarDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 행사 캘린더 수집: config event-calendar.sources 에 enabled 된 드라이버를 순회한다.
 * 한 소스 실패가 다른 소스를 막지 않는다(방어적 폴백).
 */
class EventCollectCommand extends Command
{
    protected $signature = 'event-calendar:collect {--source= : 특정 소스만(festivallife/comicworld/illustar)} {--full : 기존 저장분도 상세 재수집}';

    protected $description = '행사 캘린더 수집(내한공연·코믹월드 등)';

    /** 소스 코드 → 드라이버 클래스 레지스트리(새 소스 추가 시 여기 + config). */
    private const DRIVERS = [
        'festivallife' => FestivalLifeDriver::class,
        'comicworld' => ComicWorldDriver::class,
        'illustar' => IllustarDriver::class,
    ];

    public function handle(EventSyncService $sync, GenreTagService $tagger): int
    {
        $only = $this->option('source');
        $failures = 0;

        foreach (self::DRIVERS as $code => $class) {
            if ($only !== null && $only !== $code) {
                continue;
            }
            if (! config("event-calendar.sources.{$code}.enabled", false)) {
                continue;
            }

            /** @var EventSource $driver */
            $driver = app($class);
            $this->line("수집: {$code}...");
            try {
                $skipKeys = $this->option('full') ? [] : $sync->knownKeys($code);
                $collected = $driver->collect($skipKeys);
                $stats = $sync->sync($collected);
                $this->info('  ↳ 수집 '.count($collected)."건 · 신규 {$stats['created']} · 갱신 {$stats['updated']} · 스킵 {$stats['skipped']}");
            } catch (\Throwable $e) {
                $failures++;
                $this->warn("  ↳ 실패: {$e->getMessage()}");
                Log::error('행사 수집 실패', ['source' => $code, 'error' => $e->getMessage()]);
            }
        }

        // 공연 장르 태깅(jpop/other) — 키 없거나 실패해도 수집 결과에는 영향 없음
        try {
            $tag = $tagger->tagUntagged();
            $this->line($tag['skipped'] ? '장르 태깅: GEMINI_API_KEY 없음 — 스킵' : "장르 태깅: {$tag['tagged']}건");
        } catch (\Throwable $e) {
            Log::warning('행사 장르 태깅 오류', ['error' => $e->getMessage()]);
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
