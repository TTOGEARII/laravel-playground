@extends('layouts.app')

@section('title', '로그인 | Kanenashi Togeari')

@section('body-class', 'auth-page')

@section('vite_extra')
    @vite(['resources/js/pages/auth.js'])
@endsection

@section('content')
    <div id="auth-app" data-page="login" data-social-error="{{ session('social_error') }}"></div>
@endsection
