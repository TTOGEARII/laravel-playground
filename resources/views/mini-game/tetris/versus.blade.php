@extends('layouts.app')

@section('title', '테트리스 대전 - Mini Game')

@section('content')
    @vite(['resources/js/pages/mini-game/tetris-versus.js'])
    <div
        id="tetris-versus-app"
        data-user-id="{{ auth()->id() }}"
        data-user-name="{{ auth()->user()->name }}"
        data-home-url="{{ route('mini-game.index') }}"
        data-create-room-url="{{ route('mini-game.tetris.rooms.create') }}"
        data-csrf="{{ csrf_token() }}"
    ></div>
@endsection
