@extends('layouts.app')

@section('title', '돈없음 가시있음 · Kanenashi Togeari')

@section('header')
    <div class="header-row">
        <div class="header-brand">
            <span class="header-badge">🚀 Toy Projects</span>
            <h1>돈없음 가시있음</h1>
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
                오타쿠 굿즈 통합검색
            </p>
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
            <a href="{{ route('mini-game.index') }}" class="card-button">
                프로젝트 보기
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
            </a>
        </article>

        <!-- 프로젝트 4 -->
        <article class="project-card accent-teal">
            <div class="card-icon">🎮</div>
            <h2 class="card-title">서브컬쳐 리딤코드</h2>
            <p class="card-description">
                호요버스·블아·명조·트릭컬 코드 모아보기
            </p>
            <a href="{{ route('subculture-game-info.index') }}" class="card-button">
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
                낮에는 코드를 짜고 밤에는 최애에게 영업당하는 <strong>오타쿠 개발자</strong>입니다.<br>
                사이트 이름 그대로 <strong>돈은 없고(金無し) 고집(가시)만 있는</strong> 인간이라, 인생 목표는 단 하나 <strong>"돈 많은 백수"</strong>.
                근데 자꾸 "돈 없는 직장인"에서 세이브 포인트가 안 넘어가는 게 함정입니다.<br>
                가챠 천장은 잘만 뚫는데 통장 천장은 평생 못 뚫고, 한정 굿즈 앞에선 "이게 진짜 마지막"을 벌써 12번째 외치는 중.
                좌우명은 <strong>"내 최애는 2D, 내 잔고도 2D(이미 평면)"</strong> 입니다.<br>
                좋아하는 게임과 캐릭터를 핑계 삼아 굿즈 가격비교·AI 와이프 챗봇·미니게임·리딤코드 수집기처럼
                <strong>아무도 안 시켰는데 혼자 진심</strong>인 토이 프로젝트를 만들며 덕질과 야근 사이를 표류합니다.<br>
                오늘도 적당히 일하고 열심히 과금하며, 언젠가의 유유자적 백수 라이프를 기도메타로 빕니다. 🙏
            </p>
        </article>
    </section>
@endsection
