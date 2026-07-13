@extends('layouts.app')

@section('title', '서브컬쳐 게임 리딤코드')

@section('header')
    <div class="header-nav">
        <a href="{{ route('subculture-game-info.index') }}" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            허브로
        </a>
    </div>
    <span class="header-badge">🎮 서브컬쳐 게임</span>
    <h1>리딤코드 모아보기</h1>
    <p>원신·스타레일·젠레스·블루아카·명조·트릭컬의 리딤/쿠폰 코드를 한 곳에서.</p>
    <p>
        <a href="{{ route('subculture-game-info.info') }}" class="sgi-raids-link">🔎 정보검색(미래시·캐릭터정보·레이드) →</a>
    </p>
@endsection

@section('content')
    <div class="sgi-page">
        {{-- 게임 필터: 버튼(탭) 다중 선택. 각 버튼은 해당 게임을 선택 목록에 넣고 빼는 링크(토글) --}}
        <nav class="sgi-tabs">
            <a href="{{ route('subculture-game-info.codes') }}"
               class="sgi-tab {{ empty($selected) ? 'is-active' : '' }}">전체
                <span class="sgi-tab-unredeemed" data-unredeemed-tab="__all__" hidden></span></a>
            @foreach ($games as $game)
                @php
                    $isSel = in_array($game->slug, $selected, true);
                    $toggled = $isSel
                        ? array_values(array_diff($selected, [$game->slug]))
                        : array_values(array_merge($selected, [$game->slug]));
                    $href = $toggled
                        ? route('subculture-game-info.codes', ['game' => $toggled])
                        : route('subculture-game-info.codes');
                @endphp
                <a href="{{ $href }}" class="sgi-tab {{ $isSel ? 'is-active' : '' }}">
                    <span class="sgi-tab-icon">{{ $game->icon }}</span> {{ $game->name }}
                    {{-- 안 쓴(미교환) 코드 수 — 교환완료 상태가 클라이언트(로그인=서버값, 게스트=localStorage)라 JS 가 채운다 --}}
                    <span class="sgi-tab-unredeemed" data-unredeemed-tab="{{ $game->slug }}" hidden></span>
                </a>
            @endforeach
        </nav>

        <div class="sgi-filters">
            {{-- 교환완료 안 한 코드만 보기 --}}
            <label class="sgi-hide-redeemed-toggle">
                <input type="checkbox" id="sgi-hide-redeemed"> 교환완료 안 한 코드만 보기
            </label>

            {{-- 새 리딤코드 웹푸시 알림 (VAPID 키 설정 + 푸시 지원 브라우저에서만 노출) --}}
            @if (filled(config('services.webpush.public_key')))
                <button type="button" id="sgi-push-toggle" class="sgi-push-toggle" hidden
                        data-vapid="{{ config('services.webpush.public_key') }}">
                    🔔 <span id="sgi-push-label">새 코드 알림 받기</span>
                </button>
            @endif
        </div>

        @forelse ($groups as $g)
            <section class="sgi-game" data-game="{{ $g['game']->slug }}">
                <header class="sgi-game-head">
                    <h2 class="sgi-game-title">
                        <span class="sgi-game-icon">{{ $g['game']->icon }}</span>
                        {{ $g['game']->name }}
                        <span class="sgi-count">{{ $g['verified']->count() }}</span>
                        {{-- 아직 교환 안 한 코드 수(미검증 포함) — JS 계산 --}}
                        <span class="sgi-unredeemed" data-unredeemed-badge hidden title="아직 교환 완료 처리하지 않은 코드">안 쓴 코드 <b>0</b></span>
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
                    recountUnredeemed();
                }

                // === 안 쓴(미교환) 코드 수 배지 — 섹션 헤더 + 게임 탭 ===
                // 교환완료 상태가 클라이언트(게스트=localStorage)에만 있어 JS 로 센다.
                // 게임 필터로 화면에 없는(선택 안 된) 탭에도 숫자가 떠야 하므로,
                // DOM 카드가 아니라 서버가 내려준 게임별 검증 코드 ID 목록으로 센다.
                // 접힌 미검증(커뮤니티) 코드는 제외 — 검증 코드 수(sgi-count)와 짝이 맞아야 안 헷갈린다.
                var VERIFIED_IDS = @json($verifiedIdsByGame);

                function recountUnredeemed() {
                    var total = 0;
                    Object.keys(VERIFIED_IDS).forEach(function (slug) {
                        var n = VERIFIED_IDS[slug].filter(function (id) { return !redeemed.has(Number(id)); }).length;
                        total += n;

                        var tab = document.querySelector('[data-unredeemed-tab="' + slug + '"]');
                        if (tab) {
                            tab.hidden = n === 0;
                            tab.textContent = n;
                        }
                        var section = document.querySelector('.sgi-game[data-game="' + slug + '"]');
                        var badge = section && section.querySelector('[data-unredeemed-badge]');
                        if (badge) {
                            badge.hidden = n === 0;
                            badge.querySelector('b').textContent = n;
                        }
                    });
                    var allTab = document.querySelector('[data-unredeemed-tab="__all__"]');
                    if (allTab) {
                        allTab.hidden = total === 0;
                        allTab.textContent = total;
                    }
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

            // === 새 리딤코드 웹푸시 알림 토글 ===
            (function () {
                var btn = document.getElementById('sgi-push-toggle');
                var label = document.getElementById('sgi-push-label');
                if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

                var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                // base64url → Uint8Array (applicationServerKey 형식)
                function vapidKey() {
                    var b64 = btn.dataset.vapid.replace(/-/g, '+').replace(/_/g, '/');
                    var pad = '='.repeat((4 - b64.length % 4) % 4);
                    var raw = atob(b64 + pad);
                    return Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
                }

                function setState(subscribed) {
                    btn.classList.toggle('is-on', subscribed);
                    label.textContent = subscribed ? '새 코드 알림 켜짐' : '새 코드 알림 받기';
                }

                function api(url, body) {
                    return fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                }

                navigator.serviceWorker.ready.then(function (reg) {
                    return reg.pushManager.getSubscription();
                }).then(function (sub) {
                    btn.hidden = false;
                    setState(!!sub);
                }).catch(function () { /* SW 미등록 등 — 버튼 숨김 유지 */ });

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    navigator.serviceWorker.ready.then(function (reg) {
                        return reg.pushManager.getSubscription().then(function (existing) {
                            if (existing) {
                                // 해지
                                return api(@json(route('push.unsubscribe')), { endpoint: existing.endpoint })
                                    .then(function () { return existing.unsubscribe(); })
                                    .then(function () { setState(false); });
                            }
                            // 구독: 권한 → 브라우저 구독 → 서버 등록
                            return Notification.requestPermission().then(function (perm) {
                                if (perm !== 'granted') throw new Error('denied');
                                return reg.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: vapidKey(),
                                });
                            }).then(function (sub) {
                                var json = sub.toJSON();
                                return api(@json(route('push.subscribe')), {
                                    endpoint: sub.endpoint,
                                    keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
                                }).then(function (res) {
                                    if (!res.ok) { sub.unsubscribe(); throw new Error('server'); }
                                    setState(true);
                                });
                            });
                        });
                    }).catch(function (e) {
                        if (e && e.message === 'denied') {
                            alert('알림 권한이 차단돼 있어요. 브라우저 설정에서 이 사이트의 알림을 허용해 주세요.');
                        }
                    }).finally(function () { btn.disabled = false; });
                });
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

        /* 새 코드 웹푸시 알림 토글 — 켜짐 상태만 코랄(기능적 활성 표시) */
        .sgi-push-toggle {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: var(--ds-round-pill);
            background: transparent; border: 1px solid var(--ds-border-light);
            color: var(--ds-ink-muted); font-size: 13px; font-weight: var(--ds-fw-semibold);
            cursor: pointer; transition: color .15s, border-color .15s, background .15s;
        }
        .sgi-push-toggle:hover { color: var(--ds-ink); border-color: var(--ds-ink-soft); }
        .sgi-push-toggle.is-on {
            background: rgba(243, 114, 127, 0.14); color: var(--ds-text-accent);
            border-color: rgba(243, 114, 127, 0.5);
        }
        .sgi-push-toggle:disabled { opacity: 0.55; cursor: default; }

        /* 필터 ON: 교환완료 카드 숨김 + 카드가 모두 사라진 섹션 숨김 */
        .sgi-page.sgi-hide-redeemed .sgi-code-card.is-redeemed { display: none; }
        .sgi-hidden-empty { display: none !important; }

        /* 안 쓴(미교환) 코드 수 배지 — 탭은 숫자만, 섹션 헤더는 라벨 포함 */
        .sgi-tab-unredeemed {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 18px; height: 18px; padding: 0 5px; margin-left: 2px;
            border-radius: var(--ds-round-full);
            background: rgba(243, 114, 127, 0.18); color: var(--ds-text-accent);
            border: 1px solid rgba(243, 114, 127, 0.5);
            font-size: var(--ds-fs-micro); font-weight: var(--ds-fw-bold);
        }
        .sgi-tab.is-active .sgi-tab-unredeemed { background: rgba(243, 114, 127, 0.3); }
        .sgi-unredeemed {
            padding: 2px 10px; border-radius: var(--ds-round-full);
            background: rgba(243, 114, 127, 0.14); color: var(--ds-text-accent);
            border: 1px solid rgba(243, 114, 127, 0.45);
            font-size: 0.72rem; font-weight: var(--ds-fw-semibold);
        }
        .sgi-unredeemed b { font-weight: var(--ds-fw-bold); }
    </style>
    @endpush
@endsection
