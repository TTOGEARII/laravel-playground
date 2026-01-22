<?php

use App\Http\Controllers\OtakuShop\MainController;

Route::get('/', [MainController::class, 'index'])->name('otaku-shop.index');