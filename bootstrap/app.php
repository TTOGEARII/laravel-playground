<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // nginx 리버스 프록시(127.0.0.1) 뒤 — X-Forwarded-Proto(https) 신뢰 → 자산 URL https 생성.
        // '*'(모든 프록시 신뢰)는 외부에서 X-Forwarded-For 를 위조해 $request->ip() 를 조작(문의 IP 위조·IP 쓰로틀 우회)
        // 할 수 있으므로, 실제 프록시가 위치한 루프백/사설 대역만 신뢰한다.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        // 전역 보안 응답 헤더(클릭재킹·MIME 스니핑·리퍼러 유출 방지)를 web 응답에 부착.
        // 외부 유저 접속 로그(페이지 조회만, terminate 로 응답 후 기록).
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\LogAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
