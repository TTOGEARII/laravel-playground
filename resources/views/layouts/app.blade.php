<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Laravel Playland')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="@yield('body-class', '')">
    <div class="bg-gradient"></div>
    <div class="noise"></div>

    <div class="container">
        @hasSection('header')
            <header class="header">
                @yield('header')
            </header>
        @endif

        <main>
            @yield('content')
        </main>

        <footer class="footer">
            <p>Made with <span class="footer-heart">❤️</span> by TTOGEARII</p>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
