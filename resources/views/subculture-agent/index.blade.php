@extends('layouts.app')

@section('title', '서브컬쳐 AI 에이전트 | Kanenashi Togeari')

@section('body-class', 'sga-body')

@section('vite_extra')
    @vite(['resources/js/pages/subculture-agent.js'])
@endsection

@section('header')
    <div class="header-nav">
        <a href="/" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            돌아가기
        </a>
    </div>
    <span class="header-badge">🤖 AI Agent</span>
    <h1>서브컬쳐 AI 에이전트</h1>
    <p>리딤코드·레이드 편성·캐릭터·공략을 대화로 물어보세요.</p>
@endsection

@section('content')
    {{-- enabled: Gemini 키 유무(비활성 안내), logged-in: 페르소나에 내 캐릭터 노출 여부, games: 컨텍스트 칩 라벨 --}}
    <div id="subculture-agent-app"
         data-enabled="{{ $enabled ? '1' : '' }}"
         data-logged-in="{{ auth()->check() ? '1' : '' }}"
         data-games="{{ json_encode($games, JSON_UNESCAPED_UNICODE) }}"></div>
@endsection
