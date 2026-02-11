<?php

use App\Http\Controllers\MiniGame\MainController;

Route::get('/', [MainController::class, 'index'])->name('mini-game.index');

Route::prefix('vampire-survival')->group(function () {
    Route::get('/', [MainController::class, 'vampireSurvival'])->name('mini-game.vampire-survival.index');
});
