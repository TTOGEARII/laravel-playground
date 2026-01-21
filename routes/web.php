<?php

use App\Http\Controllers\MainController;
use Illuminate\Support\Facades\Route;

//메인
Route::get("/",[MainController::class,"index"]);

//오타쿠샵
Route::prefix("otaku-shop")->group(base_path("routes/otaku-shop.php"));