@extends('layouts.app')

@section('title', 'Tomochan is cute')

@section('header')
    <div class="header-row">
        <div class="header-brand">
            <span class="header-badge">🚀 Toy Projects</span>
            <h1>토모짱 귀여워</h1>
            <p>덕후 개발자의 은밀한 취미공간</p>
        </div>
        <div class="header-actions">
            @guest
                <a href="{{ route('login') }}" class="header-login-btn">로그인</a>
            @else
                <a href="{{ route('user.index') }}" class="header-user-link">{{ Auth::user()->name }}님 · 마이페이지</a>
            @endguest
        </div>
    </div>
@endsection

@section('content')
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
            <a href="{{ route('my-wife-bot.characters') }}" class="card-button">
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
                <span class="tag">Phaser 3</span>
            </div>
            <a href="{{ route('mini-game.index') }}" class="card-button">
                프로젝트 보기
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
            </a>
        </article>
    </section>

    <section class="projects-grid" style="margin-top: 40px;">
        <article class="project-card accent-teal">
            <div class="card-icon profile-avatar">
                <img src="/images/131544476_p0.jpg" alt="개발자 프로필 이미지">
            </div>
            <h2 class="card-title">TTOGEARII</h2>
            <p class="card-description">
                PHP/Laravel를 중심으로 웹 백엔드와 프론트엔드를 모두 다루는 6년 차 웹 개발자입니다. </br>
                NestJS와 Nuxt.js로 모던한 TypeScript 기반 애플리케이션을 구축한 경험이있고,</br>
                Docker와 AWS 환경에서 서비스의 Jenkins를 활용하여 배포·운영까지 직접 경험했습니다.
            </p>
            <div class="card-tags">
                <span class="tag">PHP</span>
                <span class="tag">Laravel</span>
                <span class="tag">NestJS</span>
                <span class="tag">Nuxt.js</span>
                <span class="tag">Docker</span>
                <span class="tag">AWS</span>
                <span class="tag">Jenkins</span>
            </div>
        </article>
    </section>
@endsection
