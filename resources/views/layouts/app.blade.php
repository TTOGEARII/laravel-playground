<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kanenashi Togeari')</title>
    {{-- PWA: 홈 화면 설치(standalone) 지원 --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#121212">
    <link rel="apple-touch-icon" href="/images/pwa/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="가시있음">
    {{-- 테마 초기화: 페인트 전에 data-theme 를 확정해 플래시(FOUC)를 막는다. 기본 다크.
         PWA 상태바(theme-color)도 현재 테마에 맞춘다.
         전환 UI는 마이페이지 설정에 있고, window.applyTheme 가 공용 진입점. --}}
    <script>
        window.applyTheme = function (theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'light' ? '#e7e8ec' : '#121212');
        };
        (function () {
            var saved = localStorage.getItem('theme');
            var theme = saved || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.setAttribute('data-theme', theme);
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) meta.setAttribute('content', theme === 'light' ? '#e7e8ec' : '#121212');
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Spotify Circular 대체 무료 폰트: Figtree(지오메트릭 산세리프) + Noto Sans KR --}}
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=Noto+Sans+KR:wght@400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @yield('vite_extra')
    @stack('styles')
</head>
<body class="@yield('body-class', '')">
    <div class="bg-gradient"></div>
    <div class="noise"></div>

    {{-- PWA 서비스워커 등록 (오프라인 폴백 + 빌드 에셋 캐시) --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js').catch(function () { /* 미지원/실패 시 조용히 무시 */ });
            });
        }
    </script>

    <div class="container">
        @hasSection('header')
            <header class="header">
                @yield('header')
            </header>
        @endif

        <main>
            @yield('content')
        </main>

        <x-site-footer />
    </div>

    @stack('scripts')
</body>
</html>
