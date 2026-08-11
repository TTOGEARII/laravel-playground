@extends('layouts.app')

@section('title', '테트리스 대전 - Mini Game')

{{-- 몰입형 게임 화면 — 전역 헤더/푸터 제외(하단 잘림 방지) --}}
@section('no-chrome', 'true')

@section('content')
    @vite(['resources/js/pages/mini-game/tetris-versus.js'])
    <div
        id="tetris-versus-app"
        data-user-id="{{ $me['id'] }}"
        data-user-name="{{ $me['name'] }}"
        data-home-url="{{ route('mini-game.index') }}"
        data-create-room-url="{{ route('mini-game.tetris.rooms.create') }}"
        data-matchmake-url="{{ route('mini-game.tetris.matchmake') }}"
        data-cancel-matchmake-url="{{ route('mini-game.tetris.matchmake.cancel') }}"
        data-csrf="{{ csrf_token() }}"
    ></div>
@endsection
