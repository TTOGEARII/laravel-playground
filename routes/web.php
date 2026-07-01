<?php

use App\Http\Controllers\InquiryController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MainController;
use App\Http\Controllers\MyWifeBot\Api\ChatController as MyWifeBotChatController;
use App\Http\Controllers\User\AuthController;
use App\Http\Controllers\User\SocialAuthController;
use App\Http\Controllers\User\UserController;
use Illuminate\Support\Facades\Route;

// 메인
Route::get('/', [MainController::class, 'index']);

// 문의 (1:1 문의/버그 제보/기능 요청) — 누구나, POST는 쓰로틀 적용
Route::get('/inquiry', [InquiryController::class, 'create'])->name('inquiry.create');
Route::post('/inquiry', [InquiryController::class, 'store'])->middleware('throttle:5,1')->name('inquiry.store');

// 약관·정책·라이센스 (정적 안내 페이지)
Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/license', [LegalController::class, 'license'])->name('legal.license');

// 인증 (로그인/회원가입/로그아웃) — POST는 쓰로틀 적용
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// 소셜 로그인 (카카오/구글) — 리다이렉트 → 제공자 동의 → 콜백
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['kakao', 'google'])
    ->middleware('throttle:10,1')
    ->name('auth.social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['kakao', 'google'])
    ->middleware('throttle:10,1')
    ->name('auth.social.callback');

// 로그인 사용자 전용 페이지
Route::middleware('auth')->group(function () {
    Route::get('/user', [UserController::class, 'index'])->name('user.index');
});

// 오타쿠샵
Route::prefix('otaku-shop')->group(base_path('routes/otaku-shop.php'));

// 미니게임
Route::prefix('mini-game')->group(base_path('routes/mini-game.php'));

// MyWifeBot (캐릭터 모아보기)
Route::prefix('my-wife-bot')->group(base_path('routes/my-wife-bot.php'));

// SubcultureGameInfo (서브컬쳐 게임 리딤코드/정보)
Route::prefix('subculture-game-info')->group(base_path('routes/subculture-game-info.php'));

// MyWifeBot 채팅 API — 세션 인증이 필요(로그인 사용자 대화 저장/이어가기)하므로 web 그룹에 둔다.
Route::prefix('api/my-wife-bot')->controller(MyWifeBotChatController::class)->group(function () {
    Route::post('chat/init', 'init');
    Route::post('chat/send', 'send');
    Route::post('chat/suggest', 'suggest');
    Route::post('chat/narrate', 'narrate');
});
