<?php

use App\Http\Controllers\SubcultureAgent\AgentController;

Route::get('/', [AgentController::class, 'index'])->name('subculture-agent.index');

// 대화 API — 세션 인증(web) 기반. Gemini 토큰 비용이 있어 스로틀은 빡빡하게.
Route::post('/chat', [AgentController::class, 'chat'])
    ->middleware('throttle:15,1')->name('subculture-agent.chat');
Route::get('/personas', [AgentController::class, 'personas'])->name('subculture-agent.personas');
Route::get('/sessions/{session}/messages', [AgentController::class, 'messages'])
    ->name('subculture-agent.messages');
