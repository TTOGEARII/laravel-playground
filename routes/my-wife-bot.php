<?php

use App\Http\Controllers\MyWifeBot\MainController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MainController::class, 'characters'])->name('my-wife-bot.characters');
