<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuck you legacy PHP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Noto+Sans+KR:wght@300;400;500;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="bg-gradient"></div>
    <div class="noise"></div>

    <div class="container">
        <header class="header">
            <span class="header-badge">🚀 Toy Projects</span>
            <h1>Fuck you legacy PHP</h1>
            <p>꼴리는대로 살거야.</p>
        </header>

        <section class="projects-grid">
            <!-- 프로젝트 1 -->
            <article class="project-card accent-indigo">
                <div class="card-icon">🛒</div>
                <h2 class="card-title">Otaku Shop</h2>
                <p class="card-description">
                    오타쿠 굿즈 쇼핑몰
                </p>
                <div class="card-tags">
                    <span class="tag">Laravel</span>
                    <span class="tag">Vue.js</span>
                    <span class="tag">MariaDB</span>
                </div>
                <a href="{{ route('otaku-shop.index') }}" class="card-button">
                    프로젝트 보기
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </article>

            <!-- 프로젝트 2 -->
            <article class="project-card accent-violet">
                <div class="card-icon">🤖</div>
                <h2 class="card-title">챗봇</h2>
                <p class="card-description">
                    일론머스크형 AI와이프좀 만들어줘
                </p>
                <div class="card-tags">
                    <span class="tag">Laravel</span>
                    <span class="tag">Gemini API</span>
                    <span class="tag">Vue.js</span>
                </div>
                <a href="#" class="card-button">
                    프로젝트 보기
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </article>

            <!-- 프로젝트 3 -->
            <article class="project-card accent-pink">
                <div class="card-icon">🎮</div>
                <h2 class="card-title">Mini Game</h2>
                <p class="card-description">
                    어머니는 웹개발자가 싫다고 하셨어
                </p>
                <div class="card-tags">
                    <span class="tag">Laravel</span>
                    <span class="tag">Vue.js</span>
                </div>
                <a href="#" class="card-button">
                    프로젝트 보기
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </article>
        </section>

        <footer class="footer">
            <p>Made with <span class="footer-heart">❤️</span> by TTOGEARII</p>
        </footer>
    </div>
</body>
</html>