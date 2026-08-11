@extends('layouts.app')

@section('title', $character['name'] . ' - 대화 | MyWifeBot')

@section('body-class', 'my-wife-bot-chat-page')

@section('vite_extra')
    @vite(['resources/js/pages/my-wife-bot.js'])
@endsection

@php
    // 캐릭터 이름 끝 글자의 받침 유무로 조사(와/과)를 정확히 고른다 — "헤르타와의" / "호로와의" / "예린과의"
    $nm = $character['name'] ?? '캐릭터';
    $lastCode = function_exists('mb_ord') ? (mb_ord(mb_substr($nm, -1), 'UTF-8') ?: 0) : 0;
    $hasJong = ($lastCode >= 0xAC00 && $lastCode <= 0xD7A3) && (($lastCode - 0xAC00) % 28) !== 0;
    $josa = $hasJong ? '과의' : '와의';
@endphp

@section('content')
    <section class="shell">
        <div class="phero">
            <a class="back" href="{{ route('my-wife-bot.characters') }}">← 캐릭터 목록</a>
            <span class="tag">🤖 MY WIFE BOT</span>
            <h1>{{ $nm }}{{ $josa }} 대화</h1>
            <p>{{ $character['description'] ?? '' }}</p>
        </div>
    </section>

    <section class="shell stack g3" style="padding-bottom:var(--s6)">
        <div id="my-wife-bot-chat-app"></div>
        <script type="application/json" id="my-wife-bot-chat-data">{!! json_encode($character, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

        <div class="row" style="justify-content:space-between">
            <span style="font-size:13px;color:var(--tx3)">대화 내용은 이어가기 목적으로 저장됩니다. 자세한 내용은 <a href="{{ route('legal.privacy') }}" style="color:var(--accent2)">개인정보처리방침</a>.</span>
            <button class="btn btn-soft" type="button" id="mw-reset-chat" data-cid="{{ $character['id'] }}">대화 초기화</button>
        </div>
    </section>
@endsection

@push('scripts')
<script>
// 대화 초기화 — 저장된 세션을 지우고 새로 시작(Chat.vue 의 localStorage 세션 키와 동일 계약)
(function () {
    var btn = document.getElementById('mw-reset-chat');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (!confirm('지금까지의 대화를 지우고 처음부터 새로 시작할까요?')) return;
        try { localStorage.removeItem('mw_session_' + btn.dataset.cid); } catch (_) {}
        window.location.reload();
    });
})();
</script>
@endpush
