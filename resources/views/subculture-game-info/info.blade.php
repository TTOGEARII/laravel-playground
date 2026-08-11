@extends('layouts.app')

@section('title', '서브컬쳐 게임 정보검색')

@section('vite_extra')
    @vite(['resources/js/pages/subculture-info.js'])
@endsection

@section('content')
    <section class="shell">
        <div class="phero">
            <a href="{{ route('subculture-game-info.index') }}" class="back">← 허브로</a>
            <span class="tag">🔎 GAME INFO</span>
            <h1>정보검색</h1>
            <p>진행중 컨텐츠 · 픽업 · 레이드 · 공략을 게임별로 한 화면에서.</p>
            <a class="btn btn-soft" href="{{ route('subculture-game-info.codes') }}">🎁 리딤코드 모아보기 →</a>
        </div>
    </section>

    {{-- 게임 탭(gamebar) + 검색 + 2열 분할(isplit) 셸은 Vue 대시보드가 렌더 --}}
    <div id="subculture-info-app"
         data-games='@json($games)'
         data-logged-in="{{ Auth::check() ? 1 : 0 }}"></div>

    <section class="shell section-sm stack g3">
        <div class="sec-head">
            <div class="stack g1">
                <span class="eyebrow">CATEGORY</span>
                <h2>무엇을 찾으세요?</h2>
            </div>
            <span>게임을 먼저 고르면 더 정확해요</span>
        </div>
        <div class="grid-2 icat">
            <a class="card cyan icard" href="#subculture-info-app">
                <span class="card-ico">📅</span>
                <div class="card-body">
                    <h3>진행중 컨텐츠</h3>
                    <p>지금 열려 있는 이벤트 · 픽업 · 상점 갱신 일정을 기간 배지와 함께.</p>
                </div>
                <span class="enter">기간별로 보기 →</span>
            </a>
            <a class="card pink icard" href="#subculture-info-app">
                <span class="card-ico">🎓</span>
                <div class="card-body">
                    <h3>모집중 학생 · 픽업</h3>
                    <p>현재 모집 중인 캐릭터와 다음 픽업(미래시)을 나란히 비교.</p>
                </div>
                <span class="enter">픽업 보기 →</span>
            </a>
            <a class="card gold icard" href="#subculture-info-app">
                <span class="card-ico">⚔️</span>
                <div class="card-body">
                    <h3>레이드 · 총력전</h3>
                    <p>보스 · 지형 · 난이도별 추천 편성과 컷 라인 정리.</p>
                </div>
                <span class="enter">편성 보기 →</span>
            </a>
            <a class="card icard" href="#subculture-info-app">
                <span class="card-ico">📖</span>
                <div class="card-body">
                    <h3>공략글 모음</h3>
                    <p>커뮤니티 · 팬사이트 공략 글을 게임별 최신순으로.</p>
                </div>
                <span class="enter">공략 보기 →</span>
            </a>
        </div>

        {{-- 캐릭터 이미지는 팬사이트 제공분을 로컬 캐시해 서빙 — 출처 고지 --}}
        <p class="sgr-image-credit">
            데이터 · 이미지 출처: 몰루로그 · 레츠도로 · Triple Lab · BD2DB · SchaleDB —
            각 게임 이미지 · 정보의 저작권은 원저작사에 있습니다. 위 카테고리는 화면 구성 안내입니다.
        </p>
    </section>
@endsection
