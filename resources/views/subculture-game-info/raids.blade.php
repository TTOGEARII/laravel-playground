@extends('layouts.app')

@section('title', '서브컬쳐 게임 레이드 정보')

@section('vite_extra')
    @vite(['resources/js/pages/subculture-raids.js'])
@endsection

@section('header')
    <div class="header-nav">
        <a href="{{ route('subculture-game-info.index') }}" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            리딤코드로
        </a>
    </div>
    <span class="header-badge">⚔️ 서브컬쳐 게임</span>
    <h1>레이드 정보 통합</h1>
    <p>블루아카·니케·트릭컬·브라운더스트2의 레이드 보스·추천 편성·커뮤니티 공략을 한 곳에서.</p>
@endsection

@section('content')
    <div id="subculture-raids-app"
         data-games='@json($games)'
         data-logged-in="{{ Auth::check() ? 1 : 0 }}"></div>
@endsection
