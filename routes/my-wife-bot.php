<?php

use App\Http\Controllers\MyWifeBot\CharacterController;
use App\Http\Controllers\MyWifeBot\MainController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MainController::class, 'characters'])->name('my-wife-bot.characters');
Route::get('/chat/{characterId}', [MainController::class, 'chat'])->name('my-wife-bot.chat');

Route::get('/characters/create', [CharacterController::class, 'addForm'])->name('my-wife-bot.characters.create');
Route::post('/characters', [CharacterController::class, 'add'])->name('my-wife-bot.characters.store');
Route::get('/characters/{character}/edit', [CharacterController::class, 'editForm'])->name('my-wife-bot.characters.edit');
Route::post('/characters/{character}/generate-greeting', [CharacterController::class, 'generateGreeting'])->name('my-wife-bot.characters.generate-greeting');
Route::put('/characters/{character}', [CharacterController::class, 'save'])->name('my-wife-bot.characters.update');
Route::delete('/characters/{character}', [CharacterController::class, 'remove'])->name('my-wife-bot.characters.destroy');
