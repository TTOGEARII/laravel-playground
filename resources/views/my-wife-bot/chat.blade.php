@extends('layouts.app')

@section('title', $character['name'] . ' - 대화 | MyWifeBot')

@section('body-class', 'my-wife-bot-chat-page')

@section('vite_extra')
    @vite(['resources/js/pages/my-wife-bot.js'])
@endsection

@section('content')
    <div id="my-wife-bot-chat-app"></div>
    <script type="application/json" id="my-wife-bot-chat-data">{!! json_encode($character, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
