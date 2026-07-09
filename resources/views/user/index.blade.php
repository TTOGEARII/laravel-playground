@extends('layouts.app')

@section('title', '마이페이지 | Kanenashi Togeari')

@section('body-class', 'user-page')

@section('vite_extra')
    @vite(['resources/js/pages/user.js'])
@endsection

@section('content')
    {{-- data-vapid: 웹푸시 공개키(미설정이면 빈 값 → 알림 설정 행 비활성) --}}
    <div id="user-app"
         data-user="{{ json_encode($user, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) }}"
         data-vapid="{{ config('services.webpush.public_key') }}"></div>
@endsection
