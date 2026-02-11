@extends('layouts.app')

@section('title', 'Mini Game - 게임 플레이랜드')

@section('header')
    <div class="header-nav">
        <a href="/" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            돌아가기
        </a>
    </div>
    <span class="header-badge">🎮 게임 플레이랜드</span>
    <h1>Mini Game</h1>
    <p>재미있는 미니게임들을 플레이해보세요!</p>
@endsection

@section('content')
    <section class="games-grid">
        @foreach($games as $game)
        <article class="game-card {{ $game['color'] }} {{ $game['status'] === 'coming-soon' ? 'coming-soon' : '' }}">
            @if($game['status'] === 'coming-soon')
                <span class="status-badge coming-soon">준비중</span>
            @else
                <span class="status-badge">플레이 가능</span>
            @endif
            
            <div class="card-icon">{{ $game['icon'] }}</div>
            <h2 class="card-title">{{ $game['name'] }}</h2>
            <p class="card-description">
                {{ $game['description'] }}
            </p>
            <div class="card-tags">
                @foreach($game['tags'] as $tag)
                    <span class="tag">{{ $tag }}</span>
                @endforeach
            </div>
            @if($game['status'] === 'coming-soon')
                <button class="card-button" disabled>
                    준비중입니다
                </button>
            @else
                <a href="{{ isset($game['route']) ? route($game['route']) : '#' }}" class="card-button">
                    게임 시작
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </a>
            @endif
        </article>
        @endforeach
    </section>
@endsection
