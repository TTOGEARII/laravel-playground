<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kanenashi Togeari')</title>
    {{-- PWA: 홈 화면 설치(standalone) 지원 --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#141026">
    <link rel="apple-touch-icon" href="/images/pwa/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="가시있음">
    {{-- 테마 초기화: 페인트 전에 data-theme 를 확정해 플래시(FOUC)를 막는다. 기본 다크(딥 퍼플).
         PWA 상태바(theme-color)도 현재 테마에 맞춘다. window.applyTheme 가 공용 진입점. --}}
    <script>
        window.applyTheme = function (theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'light' ? '#fff7f2' : '#141026');
            document.querySelectorAll('.theme-tg-lbl').forEach(function (el) { el.textContent = theme === 'light' ? 'DARK' : 'LIGHT'; });
        };
        (function () {
            var saved = localStorage.getItem('theme');
            var theme = saved || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.setAttribute('data-theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'light' ? '#fff7f2' : '#141026');
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- KT 디자인 폰트: Jua(디스플레이) + Quicksand(라벨). 본문 Pretendard 는 self-host(@font-face). --}}
    <link href="https://fonts.googleapis.com/css2?family=Jua&family=Quicksand:wght@400;500;600;700&family=Noto+Sans+KR:wght@400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @yield('vite_extra')
    @stack('styles')
</head>
<body class="@yield('body-class', '')">
    {{-- KT 배경: 도트 패턴 + 3색 블롭 글로우(고정, 콘텐츠 뒤) --}}
    <div class="bg bg-dots"></div>
    <div class="bg"><div class="blob blob-a"></div><div class="blob blob-b"></div><div class="blob blob-c"></div></div>

    {{-- PWA 서비스워커 등록 (오프라인 폴백 + 빌드 에셋 캐시) --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function () { /* 미지원/실패 시 조용히 무시 */ });
            });
        }
    </script>

    {{-- 전역 헤더(공통 셸) — 로고 + 내비 + 테마 토글 + 로그인/마이페이지 --}}
    @unless (View::hasSection('no-chrome'))
    <header class="hdr">
        <div class="shell hdr-in">
            <a class="logo" href="{{ url('/') }}">
                <span class="logo-mark">가</span>
                <span class="stack" style="line-height:1.25">
                    <span class="logo-ko">돈없음 가시있음</span>
                    <span class="logo-en">KANENASHI TOGEARI</span>
                </span>
            </a>
            <nav class="nav">
                <a href="{{ url('/otaku-shop') }}" @class(['on' => request()->is('otaku-shop*')])>오타쿠샵</a>
                <a href="{{ url('/subculture-game-info') }}" @class(['on' => request()->is('subculture-game-info*') || request()->is('subculture-agent*')])>게임 허브</a>
                <a href="{{ url('/mini-game') }}" @class(['on' => request()->is('mini-game*')])>미니게임</a>
                <a href="{{ url('/event-calendar') }}" @class(['on' => request()->is('event-calendar*')])>캘린더</a>
                <a href="{{ url('/my-wife-bot') }}" @class(['on' => request()->is('my-wife-bot*')])>챗봇</a>
                <button class="theme-tg" type="button" aria-label="테마 전환"
                    onclick="window.applyTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light')">
                    <span class="theme-tg-lbl">LIGHT</span>
                </button>
                @auth
                    <a class="btn btn-sm" href="{{ url('/user') }}">{{ auth()->user()->name }}</a>
                @else
                    <a class="btn btn-sm" href="{{ url('/login') }}">LOGIN</a>
                @endauth
            </nav>
        </div>
    </header>
    @endunless

    <main>
        @yield('content')
    </main>

    @unless (View::hasSection('no-chrome'))
        <x-site-footer />
    @endunless

    {{-- 테마 토글 라벨을 현재 테마에 맞춘다(초기 로드) --}}
    <script>
        (function () {
            var t = document.documentElement.getAttribute('data-theme');
            document.querySelectorAll('.theme-tg-lbl').forEach(function (el) { el.textContent = t === 'light' ? 'DARK' : 'LIGHT'; });
        })();
    </script>

    @stack('scripts')
</body>
</html>
