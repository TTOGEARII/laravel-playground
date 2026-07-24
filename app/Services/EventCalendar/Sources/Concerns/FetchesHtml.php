<?php

namespace App\Services\EventCalendar\Sources\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 행사 수집 드라이버 공용 HTTP 헬퍼(브라우저 UA·타임아웃·실패 로그 폴백).
 */
trait FetchesHtml
{
    protected function fetchHtml(string $url): ?string
    {
        try {
            $res = Http::withHeaders(['User-Agent' => (string) config('event-calendar.user_agent')])
                ->timeout(20)
                ->get($url);

            return $res->ok() ? $res->body() : null;
        } catch (\Throwable $e) {
            Log::warning('행사 수집 요청 실패', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** 텍스트에서 첫/마지막 날짜(YYYY.MM.DD·YYYY-MM-DD)를 [시작, 종료|null] 로 뽑는다. */
    protected function parseDateRange(string $text): array
    {
        if (! preg_match_all('/(\d{4})[.\-\/]\s?(\d{1,2})[.\-\/]\s?(\d{1,2})/u', $text, $m, PREG_SET_ORDER)) {
            return [null, null];
        }
        $dates = array_map(fn ($d) => sprintf('%04d-%02d-%02d', $d[1], $d[2], $d[3]), $m);
        $start = min($dates);
        $end = max($dates);

        return [$start, $end !== $start ? $end : null];
    }

    /** HTML 조각 → 공백 정규화 텍스트. */
    protected function textOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
