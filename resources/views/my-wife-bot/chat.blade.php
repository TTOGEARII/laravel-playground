@extends('layouts.app')

@section('title', $character['name'] . ' - 대화 | MyWifeBot')

{{-- 몰입형 풀스크린 채팅 — 전역 헤더·푸터·탭바 제외(자체 상단바로 대체) --}}
@section('no-chrome', 'true')
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
    <div class="mw-chat-wrap">
        {{-- 상단바: 캐릭터 목록으로 · 제목 · 대화 초기화 --}}
        <div class="mw-chat-topbar">
            <a class="mw-chat-icon" href="{{ route('my-wife-bot.characters') }}" aria-label="캐릭터 목록" title="캐릭터 목록">←</a>
            <span class="mw-chat-topttl">{{ $nm }}{{ $josa }} 대화</span>
            <button class="mw-chat-icon" type="button" id="mw-reset-chat" data-cid="{{ $character['id'] }}" aria-label="대화 초기화" title="대화 초기화">⟲</button>
        </div>

        {{-- Vue(.chat)가 이 안을 꽉 채운다 --}}
        <div id="my-wife-bot-chat-app" class="mw-chat-mount"></div>
        <script type="application/json" id="my-wife-bot-chat-data">{!! json_encode($character, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

        <p class="mw-chat-note">대화는 이어가기 위해 저장됩니다 · <a href="{{ route('legal.privacy') }}">개인정보처리방침</a></p>
    </div>
@endsection

@push('styles')
<style>
    /* 몰입형 풀스크린 채팅 레이아웃(PC·모바일 공통) */
    body.my-wife-bot-chat-page { overflow: hidden; } /* 페이지 스크롤 없이 채팅 로그만 스크롤 */
    .mw-chat-wrap { height: 100dvh; display: flex; flex-direction: column; }

    .mw-chat-topbar {
        flex: none;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: calc(10px + env(safe-area-inset-top, 0px)) 14px 10px;
        border-bottom: 1px solid var(--line);
        background: var(--hdr-bg);
        backdrop-filter: blur(18px);
    }
    .mw-chat-icon {
        width: 38px; height: 38px; flex: none;
        display: grid; place-items: center;
        border-radius: var(--r-pill);
        border: 1px solid var(--chip-bd);
        background: var(--chip-bg);
        color: var(--hd);
        font-size: 17px; line-height: 1;
        text-decoration: none; cursor: pointer;
        transition: .2s ease;
    }
    .mw-chat-icon:hover { border-color: var(--accent); color: var(--accent); }
    .mw-chat-topttl {
        flex: 1; min-width: 0; text-align: center;
        font-family: var(--label); font-weight: 700; font-size: 14px; color: var(--hd);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    .mw-chat-mount { flex: 1; min-height: 0; display: flex; }
    /* Vue 가 렌더한 .chat 이 마운트 영역을 꽉 채운다 */
    .my-wife-bot-chat-page .chat {
        flex: 1; min-height: 0; width: 100%;
        border-radius: 0; border-left: 0; border-right: 0;
    }
    /* 로그는 남는 공간을 모두 차지하고 스크롤(인라인 max-height 캡 무력화) */
    .my-wife-bot-chat-page .chat-log { flex: 1 1 auto; max-height: none !important; }

    .mw-chat-note {
        flex: none; text-align: center;
        font-size: 11px; color: var(--tx3);
        padding: 7px 14px calc(7px + env(safe-area-inset-bottom, 0px));
        background: var(--field); border-top: 1px solid var(--line);
    }
    .mw-chat-note a { color: var(--accent2); }

    /* PC: 화면을 꽉 채우되 채팅은 가운데 컬럼(카드) — 너무 넓게 늘어지지 않게 */
    @media (min-width: 821px) {
        .mw-chat-mount { justify-content: center; padding: 22px; }
        .my-wife-bot-chat-page .chat {
            max-width: 900px;
            border: 1px solid var(--line);
            border-radius: var(--r-l);
        }
        .mw-chat-note { border-top: 0; }
    }
</style>
@endpush

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
