@extends('layouts.app')

@section('title', '뱀파이어 서바이벌 - Mini Game')

@section('body-class', 'vampire-survival-page')

@section('content')
    <div class="game-wrapper">
        <div class="game-header-bar">
            <a href="{{ route('mini-game.index') }}" class="back-button">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                돌아가기
            </a>
            <span class="game-title">🧛 뱀파이어 서바이벌</span>
        </div>

        {{-- 시작 화면 = 메뉴(시작/옵션/조작법) → 시작 시 캐릭터 선택 --}}
        <div class="game-start-screen" id="startScreen">
            <div class="start-screen-content">
                <h2>🧛 뱀파이어 서바이벌</h2>

                {{-- 메인 메뉴 --}}
                <div id="vs-menu-main" class="vs-menu">
                    <button type="button" class="vs-menu-btn vs-menu-btn--primary" data-menu="select">시작</button>
                    <button type="button" class="vs-menu-btn" data-menu="options">옵션</button>
                    <button type="button" class="vs-menu-btn" data-menu="controls">조작법</button>
                </div>

                {{-- 시작 → 캐릭터 선택 --}}
                <div id="vs-menu-select" class="vs-menu-panel" hidden>
                    <p>캐릭터를 선택하세요</p>
                    <div class="vs-char-grid">
                        <button class="vs-char-card" data-char="rainy">
                            <div class="vs-char-portrait" style="background-image:url('/images/mini-game/vampire-survivors/charactor/rainy/rayna.webp');background-size:cover;background-position:center 12%;"></div>
                            <div class="vs-char-name">레이니</div>
                            <div class="vs-char-weapon">메인: 🌂 우산</div>
                        </button>
                        <div class="vs-char-card locked">
                            <div class="vs-char-portrait vs-locked">?</div>
                            <div class="vs-char-name">준비중</div>
                            <div class="vs-char-weapon">Coming soon</div>
                        </div>
                        <div class="vs-char-card locked">
                            <div class="vs-char-portrait vs-locked">?</div>
                            <div class="vs-char-name">준비중</div>
                            <div class="vs-char-weapon">Coming soon</div>
                        </div>
                    </div>
                    <button type="button" class="vs-back-btn" data-back>← 뒤로</button>
                </div>

                {{-- 옵션 (사운드는 추후 연동) --}}
                <div id="vs-menu-options" class="vs-menu-panel" hidden>
                    <h3 class="vs-panel-title">옵션</h3>
                    <label class="vs-opt-row">
                        <span>사운드</span>
                        <input type="checkbox" id="vs-opt-sound" checked>
                    </label>
                    <label class="vs-opt-row">
                        <span>소리 크기</span>
                        <input type="range" id="vs-opt-volume" min="0" max="100" value="70">
                    </label>
                    <p class="vs-opt-note">※ 사운드는 추후 추가 예정입니다.</p>
                    <button type="button" class="vs-back-btn" data-back>← 뒤로</button>
                </div>

                {{-- 조작법 --}}
                <div id="vs-menu-controls" class="vs-menu-panel" hidden>
                    <h3 class="vs-panel-title">조작법</h3>
                    <ul class="vs-controls-list">
                        <li><strong>이동</strong>: WASD / 방향키 · 모바일은 화면을 터치한 방향</li>
                        <li>장착한 무기는 <strong>자동으로 발동</strong>합니다.</li>
                        <li><strong>레벨 3</strong>마다 능력치, <strong>레벨 5</strong>마다 무기를 선택.</li>
                        <li><strong>특수기</strong>: 적을 처치해 게이지가 차면 <strong>Space</strong> 또는 우하단 버튼으로 전역공격.</li>
                    </ul>
                    <button type="button" class="vs-back-btn" data-back>← 뒤로</button>
                </div>
            </div>
        </div>

        <div id="game-container" style="display: none;" tabindex="0" title="터치하여 이동"></div>
    </div>

    <x-mini-game.ranking-overlay game="vampire-survival" />

    {{-- 레벨업 선택 오버레이 (능력치 / 서브무기) --}}
    <div id="vs-choice" class="vs-choice" hidden>
        <div class="vs-choice-box">
            <h3 id="vs-choice-title">레벨 업!</h3>
            <div id="vs-choice-cards" class="vs-choice-cards"></div>
        </div>
    </div>

    {{-- 특수기(전역공격) 버튼 — 처치할수록 게이지가 차고, 가득 차면 발동(Space/클릭) --}}
    <button id="vs-special-btn" class="vs-special-btn" type="button" hidden>
        <span class="vs-special-icon">🌂</span>
        <span id="vs-special-label" class="vs-special-label">0%</span>
    </button>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/phaser@3.80.1/dist/phaser.min.js"></script>
    @vite('resources/js/pages/mini-game/vampire-survival.js')
    @endpush

    @push('styles')
    <style>
        /* 시작 메뉴(시작/옵션/조작법) */
        .vs-menu { display: flex; flex-direction: column; gap: 12px; align-items: center; margin-top: 22px; }
        .vs-menu[hidden] { display: none; }
        .vs-menu-btn {
            width: 220px; padding: 13px 20px; border-radius: 12px;
            border: 1px solid #334155; background: #1e293b; color: #e2e8f0;
            font-family: 'Outfit', 'Noto Sans KR', sans-serif; font-size: 16px; font-weight: 800; cursor: pointer;
            transition: transform .12s, border-color .12s, background .12s;
        }
        .vs-menu-btn:hover { transform: translateY(-2px); border-color: #6366f1; }
        .vs-menu-btn--primary { background: #6366f1; border-color: #6366f1; color: #fff; }
        .vs-menu-panel { margin-top: 16px; }
        .vs-menu-panel[hidden] { display: none; }
        .vs-panel-title { color: #f9ed69; font-size: 18px; font-weight: 800; margin: 0 0 14px; }
        .vs-back-btn {
            margin-top: 16px; padding: 9px 18px; border-radius: 10px;
            border: 1px solid #334155; background: transparent; color: #94a3b8; font-weight: 700; cursor: pointer;
        }
        .vs-back-btn:hover { color: #e2e8f0; border-color: #475569; }
        .vs-opt-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; width: 260px; margin: 0 auto 14px; color: #cbd5e1; font-size: 15px; }
        .vs-opt-row input[type="range"] { width: 150px; }
        .vs-opt-note { color: #64748b; font-size: 12px; margin: 4px 0 0; }
        .vs-controls-list { list-style: none; padding: 0; margin: 0 auto; max-width: 400px; text-align: left; color: #cbd5e1; font-size: 14px; }
        .vs-controls-list li { padding: 7px 2px; border-bottom: 1px solid #1e293b; }

        /* 캐릭터 선택 */
        .vs-char-grid { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; margin-top: 18px; }
        .vs-char-card {
            width: 130px; background: #141426; border: 2px solid #2a2a44; border-radius: 14px;
            padding: 14px 10px; cursor: pointer; color: #e2e8f0; transition: transform .12s, border-color .12s;
            display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .vs-char-card[data-char]:hover { transform: translateY(-4px); border-color: #6366f1; }
        .vs-char-card.locked { opacity: .5; cursor: default; }
        .vs-char-portrait {
            width: 108px; height: 150px; border-radius: 12px; background-color: #0d0d1a;
            background-repeat: no-repeat; background-size: cover; background-position: center;
            display: flex; align-items: center; justify-content: center;
        }
        .vs-char-portrait.vs-locked { font-size: 44px; color: #444; }
        .vs-char-name { font-weight: 800; font-size: 15px; }
        .vs-char-weapon { font-size: 12px; color: #94a3b8; }

        /* 레벨업 선택 오버레이 */
        .vs-choice {
            position: fixed; inset: 0; z-index: 9998;
            display: flex; align-items: center; justify-content: center;
            background: rgba(2, 6, 23, 0.82); backdrop-filter: blur(4px); padding: 20px;
            font-family: 'Outfit', 'Noto Sans KR', sans-serif;
        }
        .vs-choice[hidden] { display: none; }
        .vs-choice-box { width: 100%; max-width: 560px; text-align: center; }
        .vs-choice-box h3 { color: #f9ed69; font-size: 22px; font-weight: 800; margin: 0 0 18px; }
        .vs-choice-cards { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
        .vs-choice-card {
            flex: 1 1 150px; max-width: 170px; background: #0f172a; border: 2px solid #1e293b;
            border-radius: 14px; padding: 18px 12px; cursor: pointer; color: #e2e8f0;
            display: flex; flex-direction: column; align-items: center; gap: 8px; transition: transform .12s, border-color .12s;
        }
        .vs-choice-card:hover { transform: translateY(-4px); border-color: #6366f1; }
        .vs-cc-icon { width: 56px; height: 66px; object-fit: contain; image-rendering: pixelated; }
        .vs-cc-emoji { font-size: 44px; line-height: 66px; }
        .vs-cc-title { font-weight: 800; font-size: 15px; }
        .vs-cc-desc { font-size: 12px; color: #94a3b8; }

        /* 특수기 버튼 (원형 게이지) */
        .vs-special-btn {
            position: fixed; right: 20px; bottom: 20px; z-index: 40;
            width: 92px; height: 92px; border-radius: 50%; padding: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px;
            border: 2px solid #475569; color: #e2e8f0; cursor: default;
            font-family: 'Outfit', 'Noto Sans KR', sans-serif; font-weight: 800;
            background:
                radial-gradient(circle at center, rgba(15, 23, 42, 0.92) 56%, transparent 57%),
                conic-gradient(#6366f1 var(--fill, 0%), rgba(51, 65, 85, 0.6) 0);
            transition: filter .15s ease;
        }
        .vs-special-btn[hidden] { display: none; }
        .vs-special-icon { font-size: 26px; line-height: 1; }
        .vs-special-label { font-size: 11px; color: #cbd5e1; }
        .vs-special-btn.ready {
            cursor: pointer; border-color: #fbbf24;
            box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.22), 0 0 22px rgba(251, 191, 36, 0.55);
            animation: vsSpecialPulse 0.9s ease-in-out infinite;
        }
        .vs-special-btn.ready .vs-special-label { color: #fde68a; }
        @keyframes vsSpecialPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.07); } }
        @media (max-width: 768px) { .vs-special-btn { width: 78px; height: 78px; right: 14px; bottom: 14px; } }
    </style>
    @endpush

@endsection
