@extends('layouts.app')

@section('title', '행사 캘린더 - J-pop 내한공연 · 서브컬쳐 행사 일정')

@section('vite_extra')
    @vite(['resources/js/pages/event-calendar.js'])
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
    <span class="header-badge">🗓️ Event Calendar</span>
    <h1>행사 캘린더</h1>
    <p>J-pop 내한공연과 코믹월드·일러스타페스·AGF 일정을 한눈에 확인하세요.</p>
@endsection

@section('content')
    <section class="shell phero">
        <a href="/" class="back">← 메인으로</a>
        <span class="tag">🗓️ EVENT CALENDAR</span>
        <h1>행사 캘린더</h1>
        <p>J-pop 내한공연과 코믹월드·일러스타페스·AGF 일정을 한눈에 확인하세요.</p>
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
