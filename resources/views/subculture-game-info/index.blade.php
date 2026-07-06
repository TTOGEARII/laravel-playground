@extends('layouts.app')

@section('title', '서브컬쳐 게임 리딤코드')

@section('header')
    <div class="header-nav">
        <a href="/" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            돌아가기
        </a>
    </div>
    <span class="header-badge">🎮 서브컬쳐 게임</span>
    <h1>리딤코드 모아보기</h1>
    <p>원신·스타레일·젠레스·블루아카·명조·트릭컬의 리딤/쿠폰 코드를 한 곳에서.</p>
    <p><a href="{{ route('subculture-game-info.raids.index') }}" class="sgi-raids-link">⚔️ 레이드 정보 통합 바로가기 →</a></p>
@endsection

@section('content')
    <div class="sgi-page">
        {{-- 게임 필터: 버튼(탭) 다중 선택. 각 버튼은 해당 게임을 선택 목록에 넣고 빼는 링크(토글) --}}
        <nav class="sgi-tabs">
            <a href="{{ route('subculture-game-info.index') }}"
               class="sgi-tab {{ empty($selected) ? 'is-active' : '' }}">전체</a>
            @foreach ($games as $game)
                @php
                    $isSel = in_array($game->slug, $selected, true);
                    $toggled = $isSel
                        ? array_values(array_diff($selected, [$game->slug]))
                        : array_values(array_merge($selected, [$game->slug]));
                    $href = $toggled
                        ? route('subculture-game-info.index', ['game' => $toggled])
                        : route('subculture-game-info.index');
                @endphp
                <a href="{{ $href }}" class="sgi-tab {{ $isSel ? 'is-active' : '' }}">
                    <span class="sgi-tab-icon">{{ $game->icon }}</span> {{ $game->name }}
                </a>
            @endforeach
        </nav>

        <div class="sgi-filters">
            {{-- 교환완료 안 한 코드만 보기 --}}
            <label class="sgi-hide-redeemed-toggle">
                <input type="checkbox" id="sgi-hide-redeemed"> 교환완료 안 한 코드만 보기
            </label>
        </div>

        @forelse ($groups as $g)
            <section class="sgi-game">
                <header class="sgi-game-head">
                    <h2 class="sgi-game-title">
                        <span class="sgi-game-icon">{{ $g['game']->icon }}</span>
                        {{ $g['game']->name }}
                        <span class="sgi-count">{{ $g['verified']->count() }}</span>
                    </h2>
                    @if ($g['game']->redeem_note)
                        <span class="sgi-game-note">{{ $g['game']->redeem_note }}</span>
                    @endif
                </header>

                @if ($g['verified']->isEmpty() && $g['unverified']->isEmpty())
                    <p class="sgi-empty">현재 사용 가능한 코드가 없습니다.</p>
                @endif

                @if ($g['verified']->isNotEmpty())
                    <div class="sgi-codes">
                        @foreach ($g['verified'] as $code)
                            @include('subculture-game-info.partials.code-card', ['code' => $code])
                        @endforeach
                    </div>
                @endif

                @if ($g['unverified']->isNotEmpty())
                    <details class="sgi-community">
                        <summary>🔎 미검증 (단일 출처) · {{ $g['unverified']->count() }}건 — 사용 전 확인 필요</summary>
                        <div class="sgi-codes">
                            @foreach ($g['unverified'] as $code)
                                @include('subculture-game-info.partials.code-card', ['code' => $code])
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @empty
            <p class="sgi-empty">
                등록된 게임이 없습니다.
                <code>php artisan subculture:collect</code> 로 코드를 수집하세요.
            </p>
        @endforelse
    </div>

    @push('scripts')
        <script>
            (function () {
                var IS_LOGGED_IN = @json($isLoggedIn);
                var SERVER_REDEEMED = @json($redeemedIds);
                var STORE_KEY = 'sgi_redeemed';
                var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                var STORE_URL = @json(route('subculture-game-info.redemptions.store'));
                var DESTROY_BASE = @json(url('subculture-game-info/redemptions'));

                // 교환완료 코드 ID 집합 로딩: 로그인=서버값, 비로그인=localStorage
                function loadLocal() {
                    try {
                        var raw = localStorage.getItem(STORE_KEY);
                        var arr = raw ? JSON.parse(raw) : [];
                        return Array.isArray(arr) ? arr.map(Number) : [];
                    } catch (e) { return []; }
                }
                function saveLocal(ids) {
                    try { localStorage.setItem(STORE_KEY, JSON.stringify(ids)); } catch (e) {}
                }

                var redeemed = new Set((IS_LOGGED_IN ? SERVER_REDEEMED : loadLocal()).map(Number));

                // 카드/버튼에 현재 상태 반영
                function paint(id, on) {
                    document.querySelectorAll('.sgi-code-card[data-code-id="' + id + '"]').forEach(function (card) {
                        card.classList.toggle('is-redeemed', on);
                    });
                    document.querySelectorAll('.sgi-redeemed-toggle[data-code-id="' + id + '"]').forEach(function (btn) {
                        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                        btn.querySelector('.sgi-redeemed-label').textContent = on ? '교환완료됨' : '교환완료';
                    });
                }
                redeemed.forEach(function (id) { paint(id, true); });

                // 서버 동기화(로그인 시). 실패하면 false 반환 → 호출부에서 롤백.
                function syncServer(id, on) {
                    var opts = on
                        ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: JSON.stringify({ redeem_code_id: id }) }
                        : { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } };
                    var url = on ? STORE_URL : (DESTROY_BASE + '/' + id);
                    return fetch(url, opts).then(function (res) { return res.ok; }).catch(function () { return false; });
                }

                document.querySelectorAll('.sgi-redeemed-toggle').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var id = Number(btn.dataset.codeId);
                        if (!id) return;
                        var on = !redeemed.has(id);

                        // 낙관적 UI 갱신
                        if (on) redeemed.add(id); else redeemed.delete(id);
                        paint(id, on);
                        recomputeEmpty(); // 필터 켜져 있으면 빈 섹션 갱신

                        if (IS_LOGGED_IN) {
                            btn.disabled = true;
                            syncServer(id, on).then(function (ok) {
                                btn.disabled = false;
                                if (!ok) { // 롤백
                                    if (on) redeemed.delete(id); else redeemed.add(id);
                                    paint(id, !on);
                                    recomputeEmpty();
                                }
                            });
                        } else {
                            saveLocal(Array.from(redeemed));
                        }
                    });
                });

                // === 교환완료 안 한 코드만 보기 필터 ===
                var page = document.querySelector('.sgi-page');
                var hideChk = document.getElementById('sgi-hide-redeemed');
                var HIDE_KEY = 'sgi_hide_redeemed';

                // 필터로 카드가 모두 숨겨진 섹션/커뮤니티 묶음은 통째로 숨긴다.
                function recomputeEmpty() {
                    document.querySelectorAll('.sgi-community, .sgi-game').forEach(function (box) {
                        var cards = box.querySelectorAll('.sgi-code-card');
                        var anyVisible = Array.prototype.some.call(cards, function (c) { return c.offsetParent !== null; });
                        box.classList.toggle('sgi-hidden-empty', cards.length > 0 && !anyVisible);
                    });
                }
                function applyHide(on) {
                    if (page) page.classList.toggle('sgi-hide-redeemed', on);
                    recomputeEmpty();
                }
                if (hideChk) {
                    var savedHide = localStorage.getItem(HIDE_KEY) === '1';
                    hideChk.checked = savedHide;
                    applyHide(savedHide);
                    hideChk.addEventListener('change', function () {
                        localStorage.setItem(HIDE_KEY, hideChk.checked ? '1' : '0');
                        applyHide(hideChk.checked);
                    });
                } else {
                    recomputeEmpty();
                }

            })();

            document.querySelectorAll('.sgi-copy').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var code = btn.dataset.code || '';
                    var done = function () {
                        var prev = btn.textContent;
                        btn.textContent = '복사됨';
                        btn.classList.add('is-copied');
                        setTimeout(function () { btn.textContent = prev; btn.classList.remove('is-copied'); }, 1200);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(code).then(done).catch(function () {});
                    } else {
                        var ta = document.createElement('textarea');
                        ta.value = code; document.body.appendChild(ta); ta.select();
                        try { document.execCommand('copy'); done(); } catch (e) {}
                        document.body.removeChild(ta);
                    }
                });
            });
        </script>
    @endpush

    @push('styles')
    <style>
        .sgi-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; margin-bottom: 22px; }

        /* 교환완료 필터 토글 */
        .sgi-hide-redeemed-toggle {
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            color: #cbd5e1; font-size: 14px; font-weight: 600; user-select: none;
        }
        .sgi-hide-redeemed-toggle input { width: 16px; height: 16px; accent-color: #6366f1; cursor: pointer; }

        /* 필터 ON: 교환완료 카드 숨김 + 카드가 모두 사라진 섹션 숨김 */
        .sgi-page.sgi-hide-redeemed .sgi-code-card.is-redeemed { display: none; }
        .sgi-hidden-empty { display: none !important; }
    </style>
    @endpush
@endsection
