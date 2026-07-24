<?php

namespace App\Console\Commands\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\EventSyncService;
use App\Services\EventCalendar\Sources\DTO\CollectedEventData;
use Illuminate\Console\Command;

/**
 * 수동 행사 임포트(AGF 등 크롤 가치가 낮은 연례 행사).
 * JSON 배열 파일을 source=manual 로 멱등 반영. 템플릿: database/data/events/agf.json
 */
class EventImportCommand extends Command
{
    protected $signature = 'event-calendar:import {file : 행사 JSON 파일 경로}';

    protected $description = '행사 캘린더 수동 임포트(JSON, source=manual, 멱등)';

    public function handle(EventSyncService $sync): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->error('JSON 배열 형식이 아닙니다.');

            return self::FAILURE;
        }

        $dtos = [];
        foreach ($rows as $i => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $startsOn = (string) ($row['starts_on'] ?? '');
            $kind = EventKind::tryFrom((string) ($row['kind'] ?? 'expo'));
            if ($title === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) || $kind === null) {
                $this->warn("  [{$i}] title/starts_on(Y-m-d)/kind 필수 — 스킵: ".($title ?: '(제목없음)'));

                continue;
            }
            $dtos[] = new CollectedEventData(
                source: 'manual',
                externalKey: (string) ($row['external_key'] ?? md5($title.$startsOn)),
                kind: $kind,
                title: $title,
                startsOn: $startsOn,
                endsOn: $row['ends_on'] ?? null,
                timeText: $row['time_text'] ?? null,
                venue: $row['venue'] ?? null,
                priceText: $row['price_text'] ?? null,
                ticketOpenText: $row['ticket_open_text'] ?? null,
                ticketLinks: (array) ($row['ticket_links'] ?? []),
                extra: (array) ($row['extra'] ?? []),
                posterUrl: $row['poster_url'] ?? null,
                detailUrl: $row['detail_url'] ?? null,
            );
        }

        $stats = $sync->sync($dtos);
        $this->info('완료: 신규 '.$stats['created'].' · 갱신 '.$stats['updated'].' · 스킵 '.$stats['skipped']);

        return self::SUCCESS;
    }
}
