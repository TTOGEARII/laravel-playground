@extends('layouts.app')

@section('title', '서브컬쳐 게임 정보검색')

@section('vite_extra')
    @vite(['resources/js/pages/subculture-info.js'])
@endsection

@section('header')
    <div class="header-nav">
        <a href="{{ route('subculture-game-info.index') }}" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            허브로
        </a>
    </div>
    <span class="header-badge">🔎 서브컬쳐 게임</span>
    <h1>정보검색</h1>
    <p>블루아카·원신·스타레일·젠레스·니케·트릭컬·브더2 — 진행중 컨텐츠·레이드·미래시·캐릭터정보·공략을 게임별로.</p>
@endsection

@section('content')
    <div id="subculture-info-app"
         data-games='@json($games)'
         data-logged-in="{{ Auth::check() ? 1 : 0 }}"></div>

    {{-- 캐릭터 이미지는 팬사이트 제공분을 로컬 캐시해 서빙 — 출처 고지 --}}
    <p class="sgr-image-credit">
        캐릭터 이미지 출처: 몰루로그 · 레츠도로 · Triple Lab · BD2DB —
        각 게임 이미지의 저작권은 원저작사에 있습니다.
    </p>
@endsection
