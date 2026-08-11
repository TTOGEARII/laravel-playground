@extends('layouts.app')

@section('title', '테트리스 배틀로얄 - Mini Game')

{{-- 몰입형 게임 화면 — 전역 헤더/푸터 제외(하단 잘림 방지) --}}
@section('no-chrome', 'true')

@section('content')
    @vite(['resources/js/pages/mini-game/tetris-battle.js'])
    <div
        id="tetris-battle-app"
        data-user-id="{{ $me['id'] }}"
        data-user-name="{{ $me['name'] }}"
        data-home-url="{{ route('mini-game.index') }}"
        data-create-room-url="{{ route('mini-game.tetris.rooms.create') }}"
        data-matchmake-url="{{ route('mini-game.tetris.battle.matchmake') }}"
        data-csrf="{{ csrf_token() }}"
        data-max-players="{{ $maxPlayers }}"
    ></div>
@endsection
