<?php

namespace App\Http\Controllers\EventCalendar\Api;

use App\Http\Controllers\Controller;
use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private EventService $events) {}

    /**
     * 행사 목록.
     * - 월 모드: ?year=2026&month=8 (해당 월과 겹치는 행사)
     * - 다가오는 모드: ?upcoming=1&limit=10
     * 공통 필터: kind(concert|doujin|expo|events), jpop_only(공연을 J-pop 으로 제한)
     */
    public function index(Request $request): JsonResponse
    {
        $kind = in_array($request->input('kind'), ['concert', 'doujin', 'expo', 'events'], true)
            ? $request->input('kind')
            : null;
        $jpopOnly = $request->boolean('jpop_only');

        if ($request->boolean('upcoming')) {
            $limit = min(max((int) $request->input('limit', 10), 1), 50);
            $list = $this->events->upcoming($limit, $kind, $jpopOnly);
        } else {
            $year = (int) $request->input('year', now()->year);
            $month = (int) $request->input('month', now()->month);
            abort_if($year < 2000 || $year > 2100 || $month < 1 || $month > 12, 422);
            $list = $this->events->monthEvents($year, $month, $kind, $jpopOnly);
        }

        return response()->json(['data' => $list->map(fn (Event $e) => $this->present($e))->values()]);
    }

    /** 행사 상세. */
    public function show(int $id): JsonResponse
    {
        $event = $this->events->find($id);
        abort_if($event === null, 404);

        return response()->json(['data' => $this->present($event, detail: true)]);
    }

    /** @return array<string, mixed> */
    private function present(Event $e, bool $detail = false): array
    {
        $base = [
            'id' => $e->id,
            'source' => $e->source,
            'kind' => $e->kind->value,
            'kind_label' => $e->kind->label(),
            'genre' => $e->genre,
            'title' => $e->title,
            'starts_on' => $e->starts_on->toDateString(),
            'ends_on' => $e->ends_on?->toDateString(),
            'time_text' => $e->time_text,
            'venue' => $e->venue,
            'poster_url' => $e->display_poster_url,
        ];
        if ($detail) {
            $base += [
                'price_text' => $e->price_text,
                'ticket_open_text' => $e->ticket_open_text,
                'ticket_links' => $e->ticket_links ?? [],
                'booking_text' => $e->extra['booking_text'] ?? null,
                'detail_url' => $e->detail_url,
            ];
        }

        return $base;
    }
}
