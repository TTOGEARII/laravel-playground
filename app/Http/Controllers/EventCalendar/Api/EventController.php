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

        if ($request->boolean('ticket_opens')) {
            // 다가오는 티켓 오픈(임박순) — 예매일 중심 리스트
            $limit = min(max((int) $request->input('limit', 10), 1), 50);
            $list = $this->events->upcomingTicketOpens($limit, $kind, $jpopOnly);

            return response()->json(['data' => $list->map(fn (Event $e) => $this->present($e))->values()]);
        }

        if ($request->boolean('upcoming')) {
            $limit = min(max((int) $request->input('limit', 10), 1), 50);
            $list = $this->events->upcoming($limit, $kind, $jpopOnly);

            return response()->json(['data' => $list->map(fn (Event $e) => $this->present($e))->values()]);
        }

        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        abort_if($year < 2000 || $year > 2100 || $month < 1 || $month > 12, 422);
        $list = $this->events->monthEvents($year, $month, $kind, $jpopOnly);
        $opens = $this->events->monthTicketOpens($year, $month, $kind, $jpopOnly);

        return response()->json([
            'data' => $list->map(fn (Event $e) => $this->present($e))->values(),
            // 이 달에 티켓이 '오픈'되는 공연(공연일과 별개 — 캘린더 🎫 pill 용)
            'ticket_opens' => $opens->map(fn (Event $e) => $this->present($e))->values(),
        ]);
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
            // 티켓 오픈 정보는 목록에서도 핵심(예매일 중심 UX) — 기본 포함
            'ticket_opens_on' => $e->ticket_opens_on?->toDateString(),
            'ticket_open_text' => $e->ticket_open_text,
        ];
        if ($detail) {
            $base += [
                'price_text' => $e->price_text,
                'ticket_links' => $e->ticket_links ?? [],
                'booking_text' => $e->extra['booking_text'] ?? null,
                'detail_url' => $e->detail_url,
            ];
        }

        return $base;
    }
}
