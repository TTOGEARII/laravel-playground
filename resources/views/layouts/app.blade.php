<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- 풀스크린(채팅·게임) 높이는 100dvh 대신 실제 사용가능 높이(--app-height)로 잡는다.
         안드로이드(특히 엣지투엣지)에서 100dvh 가 시스템 바 아래 영역까지 포함해 하단이 잘리는데,
         window.innerHeight 는 상태바·제스처바를 뺀 실제 웹 영역이라 이걸 CSS 변수로 노출한다. --}}
    <script>
        (function () {
            function setAppHeight() {
                var h = window.innerHeight || document.documentElement.clientHeight;
                document.documentElement.style.setProperty('--app-height', Math.round(h) + 'px');
            }
            setAppHeight();
            window.addEventListener('resize', setAppHeight);
            window.addEventListener('orientationchange', function () { setTimeout(setAppHeight, 200); });
            if (window.visualViewport) window.visualViewport.addEventListener('resize', setAppHeight);
        })();
    </script>
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
<body class="@yield('body-class', '') @hasSection('no-chrome') no-chrome @endif">
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
            <button class="burger" type="button" aria-label="메뉴" aria-expanded="false"><i></i><i></i><i></i></button>
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

        {{-- 모바일 하단 탭바(주요 메뉴) — 데스크톱은 CSS 로 숨김 --}}
        <nav class="tabbar" aria-label="주요 메뉴">
            <a href="{{ url('/') }}" @class(['on' => request()->is('/')])><span class="ti">🏠</span><span class="tl">홈</span></a>
            <a href="{{ url('/otaku-shop') }}" @class(['on' => request()->is('otaku-shop*')])><span class="ti">🛒</span><span class="tl">샵</span></a>
            <a href="{{ url('/subculture-game-info') }}" @class(['on' => request()->is('subculture-game-info*') || request()->is('subculture-agent*')])><span class="ti">🎮</span><span class="tl">게임</span></a>
            <a href="{{ url('/mini-game') }}" @class(['on' => request()->is('mini-game*')])><span class="ti">🕹️</span><span class="tl">미니게임</span></a>
            <a href="{{ url('/event-calendar') }}" @class(['on' => request()->is('event-calendar*')])><span class="ti">🗓️</span><span class="tl">캘린더</span></a>
            <a href="{{ url('/my-wife-bot') }}" @class(['on' => request()->is('my-wife-bot*')])><span class="ti">🤖</span><span class="tl">챗봇</span></a>
        </nav>
    @endunless

    {{-- 공통 셸 스크립트: js 마킹 · 테마 라벨 · 햄버거 드로어 · 이미지 폴백(noimg) --}}
    <script>
        document.documentElement.classList.add('js');
        (function () {
            var t = document.documentElement.getAttribute('data-theme');
            document.querySelectorAll('.theme-tg-lbl').forEach(function (el) { el.textContent = t === 'light' ? 'DARK' : 'LIGHT'; });
        })();

        // 모바일 햄버거 드로어(작은 화면에서 nav 펼침/접기) — 하단 탭바가 주 내비, 버거는 보조
        (function () {
            var bar = document.querySelector('.hdr-in'), nav = document.querySelector('.nav');
            if (!bar || !nav) return;
            var b = bar.querySelector('.burger');
            if (!b) return;
            bar.style.position = 'relative';
            function close() { nav.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); }
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = nav.classList.toggle('open');
                b.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            nav.addEventListener('click', function (e) { if (e.target.closest('a')) close(); });
            document.addEventListener('click', function (e) { if (!nav.contains(e.target) && !b.contains(e.target)) close(); });
            window.addEventListener('resize', function () { if (window.innerWidth > 820) close(); });
        })();

        // 이미지 로드 실패(깨진 초상화/아바타)는 컨테이너에 noimg 클래스로 폴백(🎀)
        (function () {
            function mark(img) { var w = img.parentElement; if (w && w.className && /portrait|ava|chat-ava/.test(w.className)) w.classList.add('noimg'); }
            var imgs = document.querySelectorAll('.portrait img,.ava img,.chat-ava img');
            for (var i = 0; i < imgs.length; i++) {
                var im = imgs[i];
                if (im.complete && im.naturalWidth === 0) mark(im);
                im.addEventListener('error', (function (x) { return function () { mark(x); }; })(im));
            }
        })();
    </script>

    @stack('scripts')
</body>
</html>
