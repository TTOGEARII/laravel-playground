@extends('layouts.app')

@section('title', '서브컬쳐 게임 리딤코드 | Kanenashi Togeari')

@section('content')
    <section class="shell">
        <div class="phero">
            <a class="back" href="{{ route('subculture-game-info.index') }}">← 허브로</a>
            <span class="tag">🎁 REDEEM CODES</span>
            <h1>리딤코드 모아보기</h1>
            <p>원신 · 스타레일 · 젠레스 · 블루아카 · 명조 · 트릭컬의 리딤/쿠폰 코드를 한 곳에서.</p>
            <a class="btn btn-soft" href="{{ route('subculture-game-info.info') }}">🔎 정보검색(미래시 · 캐릭터정보 · 레이드) →</a>
        </div>
    </section>

    <section class="shell stack g3">
        {{-- 게임 필터: 각 탭은 해당 게임을 선택 목록에 넣고 빼는 링크(토글) --}}
        <nav class="tabs">
            <a href="{{ route('subculture-game-info.codes') }}" class="tab {{ empty($selected) ? 'on' : '' }}">전체
                <span class="tab-unredeemed" data-unredeemed-tab="__all__" hidden></span></a>
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
                <a href="{{ $href }}" class="tab {{ $isSel ? 'on' : '' }}">
                    {{ $game->icon }} {{ $game->name }}
                    <span class="tab-unredeemed" data-unredeemed-tab="{{ $game->slug }}" hidden></span>
                </a>
            @endforeach
        </nav>

        <div class="row" style="gap:12px 18px">
            <label class="switch">
                <input type="checkbox" id="sgi-hide-redeemed"> 교환완료 안 한 코드만 보기
            </label>

            {{-- 새 리딤코드 웹푸시 알림 (VAPID 키 설정 + 푸시 지원 브라우저에서만 노출) --}}
            @if (filled(config('services.webpush.public_key')))
                <button type="button" id="sgi-push-toggle" class="push-toggle" hidden
                        data-vapid="{{ config('services.webpush.public_key') }}">
                    🔔 <span id="sgi-push-label">새 코드 알림 받기</span>
                </button>
            @endif
        </div>
    </section>

    <section class="shell codes-list" style="padding-bottom:var(--s6)">
        @forelse ($groups as $g)
            <section class="game-block" data-game="{{ $g['game']->slug }}">
                <div class="game-head">
                    <h2>{{ $g['game']->icon }} {{ $g['game']->name }}</h2>
                    <span class="count">{{ $g['verified']->count() }}</span>
                    {{-- 아직 교환 안 한 코드 수(검증 코드 기준) — JS 계산 --}}
                    <span class="game-unredeemed" data-unredeemed-badge hidden title="아직 교환 완료 처리하지 않은 코드">안 쓴 코드 <b>0</b></span>
                    @if ($g['game']->redeem_note)
                        <span class="note">{{ $g['game']->redeem_note }}</span>
                    @endif
                </div>

                @if ($g['verified']->isEmpty() && $g['unverified']->isEmpty())
                    <p class="code-empty">현재 사용 가능한 코드가 없습니다.</p>
                @endif

                @if ($g['verified']->isNotEmpty())
                    <div class="code-grid">
                        @foreach ($g['verified'] as $code)
                            @include('subculture-game-info.partials.code-card', ['code' => $code])
                        @endforeach
                    </div>
                @endif

                @if ($g['unverified']->isNotEmpty())
                    <details class="community">
                        <summary>🔎 미검증 (단일 출처) · {{ $g['unverified']->count() }}건 — 사용 전 확인 필요</summary>
                        <div class="code-grid">
                            @foreach ($g['unverified'] as $code)
                                @include('subculture-game-info.partials.code-card', ['code' => $code])
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @empty
            <div class="empty">
                <b>등록된 게임이 없습니다</b>
                <span><code>php artisan subculture:collect</code> 로 코드를 수집하세요.</span>
            </div>
        @endforelse
    </section>

    @push('styles')
        <style>
            /* 리딤코드 페이지 — kt 위에 얹는 최소 기능 스타일 */
            .codes-list { display: flex; flex-direction: column; gap: var(--s2); }
            .game-block { display: flex; flex-direction: column; }

            /* 게임 헤더 배지: 안 쓴 코드 수 */
            .game-unredeemed {
                padding: 3px 12px; border-radius: var(--r-pill);
                background: var(--chip-bg); border: 1px solid var(--accent);
                color: var(--accent); font-family: var(--label); font-weight: 700; font-size: 11px;
            }
            .game-unredeemed b { font-weight: 700; }

            /* 탭 안의 안 쓴 코드 수 */
            .tab-unredeemed {
                display: inline-flex; align-items: center; justify-content: center;
                min-width: 18px; height: 18px; padding: 0 5px; margin-left: 4px;
                border-radius: var(--r-pill);
                background: var(--accent); color: var(--on-accent);
                font-size: 10px; font-weight: 700;
            }

            /* 상태별 카드 표현 */
            .code-card.status-expired { opacity: .55; }
            .code-card.is-redeemed { opacity: .55; }
            .code-card.is-redeemed .code-val { text-decoration: line-through; text-decoration-color: var(--accent); }

            /* 교환완료 토글 버튼 */
            .code-done {
                display: inline-flex; align-items: center; gap: 5px;
                padding: 6px 12px; border-radius: var(--r-pill);
                background: var(--chip-bg); border: 1px solid var(--chip-bd);
                color: var(--tx2); font-family: var(--label); font-weight: 700; font-size: 11px;
                cursor: pointer; transition: .22s ease;
            }
            .code-done:hover { border-color: var(--accent); color: var(--accent); }
            .code-done .code-done-chk { opacity: .4; transition: opacity .2s ease; }
            .code-done[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
            .code-done[aria-pressed="true"] .code-done-chk { opacity: 1; }
            .code-done:disabled { opacity: .6; cursor: progress; }

            /* 복사 완료 표시 */
            .copy.is-copied { color: var(--accent); border-color: var(--accent); }

            /* 미검증(커뮤니티) 접기 묶음 */
            .community { border: 1px dashed var(--line); border-radius: var(--r-m); padding: 12px 16px; margin-top: var(--s2); }
            .community > summary { cursor: pointer; font-size: 13px; color: var(--tx2); padding: 4px 0; }
            .community .code-grid { margin-top: var(--s3); }

            .code-empty { color: var(--tx3); font-size: 14px; padding: 8px 0; }

            /* 새 코드 웹푸시 알림 토글 — 켜짐 상태만 강조 */
            .push-toggle {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 6px 14px; border-radius: var(--r-pill);
                background: transparent; border: 1px solid var(--chip-bd);
                color: var(--tx2); font-family: var(--label); font-weight: 700; font-size: 12px;
                cursor: pointer; transition: .22s ease;
            }
            .push-toggle:hover { color: var(--accent); border-color: var(--accent); }
            .push-toggle.is-on { background: var(--chip-bg); color: var(--accent); border-color: var(--accent); }
            .push-toggle:disabled { opacity: .55; cursor: default; }

            /* 필터 ON: 교환완료 카드 숨김 + 카드가 모두 사라진 묶음 숨김 */
            .codes-list.is-hide-redeemed .code-card.is-redeemed { display: none; }
            .is-empty-hidden { display: none !important; }
        </style>
    @endpush

    @push('scripts')
        <script>
            (function () {
                var IS_LOGGED_IN = @json($isLoggedIn);
                var SERVER_REDEEMED = @json($redeemedIds);
                var STORE_KEY = 'sgi_redeemed';
                var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                var STORE_URL = @json(route('subculture-game-info.redemptions.store'));
                var DESTROY_BASE = @json(url('subculture-game-info/redemptions'));

                // 교환완료 코드 ID 집합: 로그인=서버값, 비로그인=localStorage
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
                    document.querySelectorAll('.code-card[data-code-id="' + id + '"]').forEach(function (card) {
                        card.classList.toggle('is-redeemed', on);
                    });
                    document.querySelectorAll('.js-redeemed[data-code-id="' + id + '"]').forEach(function (btn) {
                        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                        btn.querySelector('.code-done-lbl').textContent = on ? '교환완료됨' : '교환완료';
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

                document.querySelectorAll('.js-redeemed').forEach(function (btn) {
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
                var listEl = document.querySelector('.codes-list');
                var hideChk = document.getElementById('sgi-hide-redeemed');
                var HIDE_KEY = 'sgi_hide_redeemed';

                // 필터로 카드가 모두 숨겨진 섹션/커뮤니티 묶음은 통째로 숨긴다.
                function recomputeEmpty() {
                    document.querySelectorAll('.community, .game-block').forEach(function (box) {
                        var cards = box.querySelectorAll('.code-card');
                        var anyVisible = Array.prototype.some.call(cards, function (c) { return c.offsetParent !== null; });
                        box.classList.toggle('is-empty-hidden', cards.length > 0 && !anyVisible);
                    });
                    recountUnredeemed();
                }

                // === 안 쓴(미교환) 코드 수 배지 — 섹션 헤더 + 게임 탭 ===
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
                        var section = document.querySelector('.game-block[data-game="' + slug + '"]');
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
                    if (listEl) listEl.classList.toggle('is-hide-redeemed', on);
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

                // 이 토글은 'redeem' 주제만 담당 — 다른 주제 구독은 건드리지 않는다
                var currentTopics; // undefined=서버 미등록, null=전체 수신, 배열=선택 주제
                function redeemOn() {
                    return currentTopics === null || (Array.isArray(currentTopics) && currentTopics.indexOf('redeem') !== -1);
                }

                navigator.serviceWorker.ready.then(function (reg) {
                    return reg.pushManager.getSubscription();
                }).then(function (sub) {
                    btn.hidden = false;
                    if (!sub) { setState(false); return; }
                    return api(@json(route('push.status')), { endpoint: sub.endpoint })
                        .then(function (res) { return res.json(); })
                        .then(function (json) {
                            if (json.data && json.data.subscribed) currentTopics = json.data.topics;
                            setState(redeemOn());
                        });
                }).catch(function () { /* SW 미등록 등 — 버튼 숨김 유지 */ });

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    navigator.serviceWorker.ready.then(function (reg) {
                        return reg.pushManager.getSubscription().then(function (existing) {
                            if (existing && currentTopics !== undefined && redeemOn()) {
                                var next = (currentTopics === null ? ['concert', 'event'] : currentTopics.filter(function (t) { return t !== 'redeem'; }));
                                return api(@json(route('push.topics')), { endpoint: existing.endpoint, topics: next })
                                    .then(function (res) { return res.json(); })
                                    .then(function (json) { currentTopics = json.data.topics; setState(false); });
                            }
                            if (existing) {
                                var json = existing.toJSON();
                                return api(@json(route('push.subscribe')), {
                                    endpoint: existing.endpoint,
                                    keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
                                    topics: ['redeem'],
                                }).then(function (res) { return res.json(); })
                                    .then(function (json2) { currentTopics = json2.data.topics; setState(true); });
                            }
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
                                    topics: ['redeem'],
                                }).then(function (res) {
                                    if (!res.ok) { sub.unsubscribe(); throw new Error('server'); }
                                    return res.json();
                                }).then(function (json2) { currentTopics = json2.data.topics; setState(true); });
                            });
                        });
                    }).catch(function (e) {
                        if (e && e.message === 'denied') {
                            alert('알림 권한이 차단돼 있어요. 브라우저 설정에서 이 사이트의 알림을 허용해 주세요.');
                        }
                    }).finally(function () { btn.disabled = false; });
                });
            })();

            // === 코드 복사 ===
            document.querySelectorAll('.js-copy').forEach(function (btn) {
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
@endsection
