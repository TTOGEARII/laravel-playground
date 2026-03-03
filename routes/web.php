<?php

use App\Http\Controllers\MainController;
use App\Http\Controllers\User\AuthController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

//메인
Route::get("/",[MainController::class,"index"]);

// 인증 (로그인/회원가입/로그아웃) — POST는 쓰로틀 적용
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// 로그인 사용자 전용 페이지
Route::middleware('auth')->group(function () {
    Route::get('/user', [UserController::class, 'index'])->name('user.index');
});

//오타쿠샵
Route::prefix("otaku-shop")->group(base_path("routes/otaku-shop.php"));

//미니게임
Route::prefix("mini-game")->group(base_path("routes/mini-game.php"));

// MyWifeBot (캐릭터 모아보기)
Route::prefix("my-wife-bot")->group(base_path("routes/my-wife-bot.php"));