@extends('layouts.app')

@section('title', '행사 캘린더 - J-pop 내한공연 · 서브컬쳐 행사 일정')

@section('vite_extra')
    @vite(['resources/js/pages/event-calendar.js'])
@endsection

@push('styles')
    {{-- 월 그리드 기본 레이아웃(.cal/.cal-head/.cal-dow/.cal-grid/.cal-cell/.cal-d)은 목업의 <style> 에만
         정의돼 있고 design-system.css 에는 반응형 오버라이드만 있다. 공유 CSS 를 수정하지 않고 목업과
         동일하게 렌더하려면 페이지에서 기본 정의를 보강해야 한다. @stack('styles') 는 app.css 뒤에
         로드되므로, design-system 의 반응형 값(≤820/560/520px)을 함께 재확인해 회귀를 막는다. --}}
    <style>
        .cal { background: var(--ink2); border: 1px solid var(--line); border-radius: var(--r-l); box-shadow: var(--shadow-soft); padding: var(--pad-card); display: flex; flex-direction: column; gap: var(--s3); }
        .cal-head { display: flex; align-items: center; justify-content: space-between; gap: var(--s2); flex-wrap: wrap; }
        .cal-month { font-family: var(--disp); font-size: 26px; color: var(--hd); }
        .cal-dow { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        .cal-dow span { font-family: var(--label); font-weight: 700; font-size: 11px; letter-spacing: .12em; color: var(--tx3); text-align: center; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        .cal-cell { border-radius: var(--r-s); border: 1px solid var(--line); background: var(--field); padding: 10px; display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
        .cal-cell.off { background: transparent; border-color: transparent; }
        .cal-d { font-family: var(--label); font-weight: 700; font-size: 12px; color: var(--tx2); }
        /* design-system.css 반응형 값 재확인(로드 순서상 뒤라 회귀 방지) */
        @media (max-width: 820px) { .cal { padding: 16px; } .cal-head { flex-direction: column; align-items: stretch; gap: 14px; } .cal-head .chips { justify-content: center; } }
        @media (max-width: 560px) { .cal-cell { padding: 5px 4px; gap: 4px; } .cal-d { font-size: 11px; } }
        @media (max-width: 520px) { .cal-dow span { font-size: 9px; letter-spacing: .06em; } .cal-grid, .cal-dow { gap: 5px; } .cal-month { font-size: 22px; } }
    </style>
@endpush

@section('content')
    <section class="shell">
        <div class="phero">
            <a href="/" class="back">← 돌아가기</a>
            <span class="tag">🗓️ EVENT CALENDAR</span>
            <h1>행사 캘린더</h1>
            <p>J-pop 내한공연과 코믹월드 · 일러스타페스 · AGF 일정을 한눈에 확인하세요.</p>
        </div>
    </section>

    {{-- data-event-id: 상세 딥링크(/event-calendar/{id}) 진입 시 해당 행사 상세를 바로 연다 --}}
    {{-- data-vapid: 웹푸시 공개키(미설정이면 알림 설정 버튼 숨김) --}}
    <div id="event-calendar-app" data-event-id="{{ $eventId ?? '' }}"
        data-vapid="{{ config('services.webpush.public_key') }}"></div>

    <p class="ec-source-note">
        일정 출처: <a href="https://j-pop-playlist.tistory.com/1109" target="_blank" rel="noopener">짱짱이의 내한 캘린더</a> ·
        <a href="https://mycon.me" target="_blank" rel="noopener">mycon</a>(예매 정보) ·
        <a href="https://comicw.net" target="_blank" rel="noopener">코믹월드</a> ·
        <a href="https://illustar.net" target="_blank" rel="noopener">일러스타페스</a> ·
        <a href="https://www.agfkorea.com" target="_blank" rel="noopener">AGF</a>
        — 정확한 정보는 각 공식 페이지를 확인해 주세요.
    </p>
@endsection
