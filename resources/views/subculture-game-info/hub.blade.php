@extends('layouts.app')

@section('title', '서브컬쳐 게임 허브 | Kanenashi Togeari')

@section('content')
    <section class="shell">
        <div class="phero">
            <a class="back" href="{{ url('/') }}">← 돌아가기</a>
            <span class="tag">🎮 SUBCULTURE HUB</span>
            <h1>무엇을 찾으세요?</h1>
            <p>리딤코드부터 미래시 · 캐릭터정보 · 레이드 편성까지 — 원하는 걸 골라 들어가세요.</p>
        </div>
    </section>

    <section class="shell stack g3">
        <div class="grid">
            <a class="card pink" href="{{ route('subculture-game-info.codes') }}" style="min-height:280px">
                <span class="card-no">01</span>
                <span class="card-ico">🎁</span>
                <div class="card-body">
                    <h3>서브컬쳐 리딤코드</h3>
                    <p>호요버스 · 블아 · 명조 · 트릭컬 · 니케 · 브더2의 리딤/쿠폰 코드를 한 곳에서. 안 쓴 코드 배지와 새 코드 알림까지.</p>
                    <span class="enter">코드 보러 가기 →</span>
                </div>
            </a>
            <a class="card cyan" href="{{ route('subculture-game-info.info') }}" style="min-height:280px">
                <span class="card-no">02</span>
                <span class="card-ico">🔎</span>
                <div class="card-body">
                    <h3>서브컬쳐 정보검색</h3>
                    <p>진행중 컨텐츠 · 모집중 학생 · 레이드 · 공략글을 게임별로. 미래시 · 캐릭터정보 탐색까지 한 화면에서.</p>
                    <span class="enter">정보 탐색하기 →</span>
                </div>
            </a>
            <a class="card gold" href="{{ route('subculture-agent.index') }}" style="min-height:280px">
                <span class="card-no">03</span>
                <span class="card-ico">🤖</span>
                <div class="card-body">
                    <h3>서브컬쳐 AI 에이전트</h3>
                    <p>리딤코드 · 레이드 편성 · 공략을 대화로 물어보세요. 페르소나(모루 · 선배 · 집사 · 내 챗봇)를 골라 대화할 수 있어요.</p>
                    <span class="enter">AI와 대화하기 →</span>
                </div>
            </a>
        </div>
    </section>
@endsection
