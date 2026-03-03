@extends('layouts.app')

@section('title', '마이페이지 | Laravel Playland')

@section('body-class', 'user-page')

@section('vite_extra')
    @vite(['resources/js/pages/user.js'])
@endsection

@section('content')
    <div id="user-app" data-user="{{ json_encode($user, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) }}"></div>
@endsection
