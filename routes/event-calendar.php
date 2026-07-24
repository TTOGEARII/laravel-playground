<?php

use App\Http\Controllers\EventCalendar\MainController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 행사 캘린더 (J-pop 내한공연 + 서브컬쳐 오프라인 행사) — prefix: /event-calendar
|--------------------------------------------------------------------------
*/

Route::get('/', [MainController::class, 'index'])->name('event-calendar.index');
Route::get('/{id}', [MainController::class, 'show'])->whereNumber('id')->name('event-calendar.show');
