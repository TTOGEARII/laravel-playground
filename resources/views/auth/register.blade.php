@extends('layouts.app')

@section('title', '회원가입 | Laravel Playland')

@section('body-class', 'auth-page')

@section('vite_extra')
    @vite(['resources/js/pages/auth.js'])
@endsection

@section('content')
    <div id="auth-app" data-page="register"></div>
@endsection
