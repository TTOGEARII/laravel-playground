<?php

use App\Http\Controllers\SubcultureGameInfo\MainController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MainController::class, 'index'])->name('subculture-game-info.index');
