@extends('layouts.app')

@section('title', '서브컬쳐 AI 에이전트 | Kanenashi Togeari')

@section('body-class', 'sga-body')

@section('vite_extra')
    @vite(['resources/js/pages/subculture-agent.js'])
@endsection

@section('content')
    <section class="shell">
        <div class="phero">
            <a href="{{ route('subculture-game-info.index') }}" class="back">← 게임 허브로</a>
            <span class="tag">🤖 AI AGENT</span>
            <h1>서브컬쳐 AI 에이전트</h1>
            <p>리딤코드 · 레이드 편성 · 캐릭터 · 공략을 대화로 물어보세요.</p>
        </div>
    </section>

    {{-- enabled: Gemini 키 유무(비활성 안내), logged-in: 페르소나에 내 캐릭터 노출 여부, games: 컨텍스트 칩 라벨 --}}
    <div id="subculture-agent-app"
         data-enabled="{{ $enabled ? '1' : '' }}"
         data-logged-in="{{ auth()->check() ? '1' : '' }}"
         data-games="{{ json_encode($games, JSON_UNESCAPED_UNICODE) }}"></div>
@endsection
