<?php

namespace App\Http\Controllers\EventCalendar;

use App\Http\Controllers\Controller;
use App\Services\EventCalendar\EventService;
use Illuminate\View\View;

class MainController extends Controller
{
    public function __construct(private EventService $events) {}

    /** 캘린더 메인. */
    public function index(): View
    {
        return view('event-calendar.index', ['eventId' => null]);
    }

    /** 상세 딥링크(/event-calendar/{id}) — 같은 앱을 상세 열림 상태로 마운트(공유 가능 URL). */
    public function show(int $id): View
    {
        abort_if($this->events->find($id) === null, 404);

        return view('event-calendar.index', ['eventId' => $id]);
    }
}
