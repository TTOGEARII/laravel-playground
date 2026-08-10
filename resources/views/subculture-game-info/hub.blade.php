@extends('layouts.app')

@section('title', '서브컬쳐 게임 허브 | Kanenashi Togeari')

@section('header')
    <div class="header-nav">
        <a href="/" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            돌아가기
        </a>
    </div>
    <span class="header-badge">🎮 서브컬쳐 게임 허브</span>
    <h1>무엇을 찾으세요?</h1>
    <p>리딤코드부터 미래시·캐릭터정보·레이드 편성까지 — 원하는 걸 골라 들어가세요.</p>
@endsection

@section('content')
    <section class="shell phero">
        <a href="/" class="back">← 메인으로</a>
        <span class="tag">🎮 서브컬쳐 게임 허브</span>
        <h1>무엇을 찾으세요?</h1>
        <p>리딤코드부터 미래시·캐릭터정보·레이드 편성까지 — 원하는 걸 골라 들어가세요.</p>
    </section>

    <section class="shell section" style="padding-top:0">
        <div class="grid">
            <a class="card pink" href="{{ route('subculture-game-info.codes') }}">
                <span class="card-ico">🎁</span>
                <div class="card-body">
                    <h3>서브컬쳐 리딤코드</h3>
                    <p>호요버스·블아·명조·트릭컬·니케·브더2의 리딤/쿠폰 코드를 한 곳에서. 안 쓴 코드 배지와 새 코드 알림까지.</p>
                    <span class="enter">코드 보러 가기 →</span>
                </div>
            </a>
            <a class="card cyan" href="{{ route('subculture-game-info.info') }}">
                <span class="card-ico">🔎</span>
                <div class="card-body">
                    <h3>서브컬쳐 정보검색</h3>
                    <p>진행중 컨텐츠·모집중 학생·레이드·공략글을 게임별로. 미래시·캐릭터정보 탐색까지 한 화면에서.</p>
                    <span class="enter">정보 탐색하기 →</span>
                </div>
            </a>
            <a class="card gold" href="{{ route('subculture-agent.index') }}">
                <span class="card-ico">🤖</span>
                <div class="card-body">
                    <h3>서브컬쳐 AI 에이전트</h3>
                    <p>리딤코드·레이드 편성·공략을 대화로 물어보세요. 페르소나(모루·선배·집사·내 챗봇)를 골라 대화할 수 있어요.</p>
                    <span class="enter">AI와 대화하기 →</span>
                </div>
            </a>
        </div>
    </section>
@endsection
