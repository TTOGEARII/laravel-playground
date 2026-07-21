<?php

use App\Http\Controllers\MiniGame\MainController;
use App\Http\Controllers\MiniGame\ScoreController;
use App\Http\Controllers\MiniGame\TetrisVersusController;
use App\Http\Middleware\EnsureTetrisIdentity;

Route::get('/', [MainController::class, 'index'])->name('mini-game.index');

// 점수 랭킹 API (내가 만든 게임 공통) — 등록은 스팸 방지 쓰로틀 적용
Route::get('rankings', [ScoreController::class, 'all'])->name('mini-game.rankings');
Route::post('scores', [ScoreController::class, 'store'])->middleware('throttle:20,1')->name('mini-game.scores.store');

Route::prefix('vampire-survival')->group(function () {
    Route::get('/', [MainController::class, 'vampireSurvival'])->name('mini-game.vampire-survival.index');
});

Route::prefix('tetris')->group(function () {
    Route::get('/', [MainController::class, 'tetris'])->name('mini-game.tetris.index');

    // 실시간 대전(Reverb) — 로그인 사용자·게스트 모두 허용(EnsureTetrisIdentity 가 게스트 신원 부여).
    Route::middleware(EnsureTetrisIdentity::class)->group(function () {
        Route::get('versus', [TetrisVersusController::class, 'index'])->name('mini-game.tetris.versus');
        Route::post('rooms', [TetrisVersusController::class, 'createRoom'])
            ->middleware('throttle:30,1')->name('mini-game.tetris.rooms.create');
        // 빠른 대전(폴링) — 2초 간격 폴이라 스로틀 여유있게.
        Route::post('matchmake', [TetrisVersusController::class, 'matchmake'])
            ->middleware('throttle:90,1')->name('mini-game.tetris.matchmake');
        Route::post('matchmake/cancel', [TetrisVersusController::class, 'cancelMatchmake'])
            ->middleware('throttle:30,1')->name('mini-game.tetris.matchmake.cancel');
    });
});

Route::prefix('doom')->group(function () {
    Route::get('/', [MainController::class, 'doom'])->name('mini-game.doom.index');
});
