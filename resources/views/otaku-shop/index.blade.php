@extends('layouts.app')

@section('title', 'Otaku Shop - 오타쿠 굿즈 통합검색')

@section('vite_extra')
    @vite(['resources/js/pages/otaku-shop.js'])
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
    <span class="header-badge">🛒 Otaku Shop</span>
    <h1>오타쿠 굿즈 통합검색</h1>
    <p>여러 쇼핑몰을 한 번에 검색하고 가격을 비교해 보세요.</p>
@endsection

@section('content')
    {{-- data-logged-in: 찜(재입고 알림)은 로그인 전용 — Vue 가 하트 버튼 동작을 분기한다 --}}
    <div id="otaku-shop-app" data-logged-in="{{ auth()->check() ? '1' : '' }}"></div>
@endsection
