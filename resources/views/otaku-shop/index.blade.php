@extends('layouts.app')

@section('title', 'Otaku Shop - 가격비교 쇼핑몰')

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
    <h1>오타쿠 굿즈 가격비교</h1>
    <p>여러 쇼핑몰의 가격과 혜택을 한눈에 비교해 보세요.</p>
@endsection

@section('content')
    <div id="otaku-shop-app"></div>
@endsection
