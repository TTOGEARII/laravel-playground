<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kanenashi Togeari')</title>
    {{-- 테마 초기화: 페인트 전에 data-theme 를 확정해 플래시(FOUC)를 막는다. 기본 다크. --}}
    <script>
        (function () {
            var saved = localStorage.getItem('theme');
            var theme = saved || (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            document.documentElement.setAttribute('data-theme', theme);
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

    {{-- 라이트/다크 테마 토글 (우상단 고정) --}}
    <button type="button" id="theme-toggle" class="ds-theme-toggle" aria-label="테마 전환" title="라이트/다크 전환">
        <span class="ds-theme-toggle-icon" data-icon-dark>🌙</span>
        <span class="ds-theme-toggle-icon" data-icon-light>☀️</span>
    </button>
    <script>
        (function () {
            var btn = document.getElementById('theme-toggle');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('theme', next);
            });
        })();
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
