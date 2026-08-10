@extends('layouts.app')

@section('title', '돈없음 가시있음 · Kanenashi Togeari')

@section('content')
    {{-- 홈 전용 티커(흐르는 띠) --}}
    <div class="ticker">
        <div class="ticker-track">
            <span>지갑 잔고 <b>2D</b></span><span>가챠 천장 <b>돌파</b></span><span>한정 굿즈 <b>"이게 진짜 마지막" ×12</b></span><span>인생 목표 <b>돈 많은 백수</b></span><span>현재 상태 <b>돈 없는 직장인</b></span>
            <span>지갑 잔고 <b>2D</b></span><span>가챠 천장 <b>돌파</b></span><span>한정 굿즈 <b>"이게 진짜 마지막" ×12</b></span><span>인생 목표 <b>돈 많은 백수</b></span><span>현재 상태 <b>돈 없는 직장인</b></span>
        </div>
    </div>

    {{-- 히어로 --}}
    <section id="top" class="shell section hero">
        <div class="stack g3">
            <span class="tag">TOY PROJECTS ★ 2026</span>
            <h1>돈없음<br><em>가시있음</em></h1>
            <p>덕후 개발자의 은밀한 취미공간.<br>아무도 안 시켰는데 혼자 진심인 것들만 모아뒀습니다.</p>
            <div class="row" style="margin-top:var(--s1)">
                <a class="btn btn-fill" href="#projects">프로젝트 보기</a>
                @guest
                    <a class="btn btn-ghost" href="{{ route('login') }}">로그인</a>
                @else
                    <a class="btn btn-ghost" href="{{ route('user.index') }}">마이페이지</a>
                @endguest
            </div>
            <div class="stats" style="margin-top:var(--s3)">
                <div><b>05</b><small>PROJECTS</small></div>
                <div><b>2D</b><small>최애 &amp; 잔고</small></div>
                <div><b>∞</b><small>야근 &amp; 덕질</small></div>
            </div>
        </div>
        <div class="pcard-wrap">
            <div class="pcard">
                <div class="pcard-top"><span>PLAYER PROFILE</span><span>● ONLINE</span></div>
                <div class="portrait" style="height:200px"><img src="/images/131544476_p0.jpg" alt="TTOGEARII 프로필 캐릭터" loading="eager"></div>
                <div class="stack g1">
                    <span class="pname">TTOGEARII</span>
                    <span class="psub">오타쿠 개발자 · Lv.???</span>
                </div>
                <div class="chips">
                    <span class="chip pink">#가챠천장돌파</span>
                    <span class="chip cyan">#통장천장미달</span>
                    <span class="chip">#돈많은백수희망</span>
                </div>
            </div>
        </div>
    </section>

    {{-- 프로젝트 목록 --}}
    <section id="projects" class="shell section">
        <div class="sec-head home-head">
            <div class="stack g1">
                <span class="eyebrow">PROJECT LIST</span>
                <h2>혼자 진심인 것들</h2>
            </div>
            <span>전부 무료 · 전부 취미</span>
        </div>
        <div class="grid">
            <a class="card pink wide" href="{{ route('otaku-shop.index') }}">
                <span class="card-no">01</span>
                <span class="card-ico">🛒</span>
                <div class="card-body">
                    <h3>Otaku Shop</h3>
                    <p>오타쿠 굿즈 통합검색 — 최저가 찾으러 왔다가 결국 다 사서 나가는 곳</p>
                    <span class="enter">ENTER →</span>
                </div>
            </a>
            <a class="card" href="{{ route('my-wife-bot.characters') }}">
                <span class="card-no">02</span>
                <span class="card-ico">🤖</span>
                <div class="card-body">
                    <h3>챗봇</h3>
                    <p>일론머스크형 AI와이프좀 만들어줘</p>
                    <span class="enter">ENTER →</span>
                </div>
            </a>
            <a class="card" href="{{ route('mini-game.index') }}">
                <span class="card-no">03</span>
                <span class="card-ico">🎮</span>
                <div class="card-body">
                    <h3>Mini Game</h3>
                    <p>어머니는 웹개발자가 싫다고 하셨어</p>
                    <span class="enter">ENTER →</span>
                </div>
            </a>
            <a class="card pink" href="{{ route('subculture-game-info.index') }}">
                <span class="card-no">04</span>
                <span class="card-ico">🕹️</span>
                <div class="card-body">
                    <h3>서브컬쳐 게임 허브</h3>
                    <p>리딤코드 · 미래시 · 캐릭터정보 · 레이드 · AI 물어보기</p>
                    <span class="enter">ENTER →</span>
                </div>
            </a>
            <a class="card cyan" href="{{ route('event-calendar.index') }}">
                <span class="card-no">05</span>
                <span class="card-ico">🗓️</span>
                <div class="card-body">
                    <h3>행사 캘린더</h3>
                    <p>J-pop 내한 · 코믹월드 · 일러스타페스 · AGF 일정을 한눈에</p>
                    <span class="enter">ENTER →</span>
                </div>
            </a>
        </div>
    </section>

    {{-- 소개 --}}
    <section id="about" class="shell section">
        <div class="about">
            <div class="stack g3">
                <div class="portrait" style="width:200px;aspect-ratio:3/4"><img src="/images/131544476_p0.jpg" alt="TTOGEARII 프로필 캐릭터" loading="eager"></div>
                <span class="owner">SITE OWNER / 金無し棘有り</span>
            </div>
            <div class="stack g3">
                <span class="eyebrow">ABOUT ME</span>
                <h2>낮에는 코드,<br>밤에는 영업당하는 중</h2>
                <p>사이트 이름 그대로 <strong>돈은 없고(金無し) 고집(가시)만 있는</strong> 인간이라, 인생 목표는 단 하나 <strong>"돈 많은 백수"</strong>. 근데 자꾸 "돈 없는 직장인"에서 세이브 포인트가 안 넘어가는 게 함정입니다.</p>
                <p>가챠 천장은 잘만 뚫는데 통장 천장은 평생 못 뚫고, 한정 굿즈 앞에선 "이게 진짜 마지막"을 벌써 12번째 외치는 중. 좋아하는 게임과 캐릭터를 핑계 삼아 굿즈 가격비교 · AI 와이프 챗봇 · 미니게임 · 리딤코드 수집기처럼 아무도 안 시켰는데 혼자 진심인 토이 프로젝트를 만들며 덕질과 야근 사이를 표류합니다.</p>
                <blockquote class="quote">"내 최애는 2D, 내 잔고도 2D (이미 평면)"</blockquote>
            </div>
        </div>
    </section>

    {{-- 푸시 알림 테스트 — 누른 브라우저(기기)에만 발송. VAPID 설정 + 푸시 지원 브라우저에서만 노출 --}}
    @if (filled(config('services.webpush.public_key')))
        <section class="push-test shell">
            <button type="button" id="push-test-btn" class="push-test-btn" hidden
                    data-vapid="{{ config('services.webpush.public_key') }}">
                🔔 푸시 알림 테스트
            </button>
            <span id="push-test-status" class="push-test-status" role="status"></span>
        </section>

        @push('styles')
        <style>
            .push-test { display: flex; align-items: center; gap: 12px; justify-content: center; margin-top: 28px; }
            .push-test-btn {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 8px 18px; border-radius: var(--ds-round-pill);
                background: transparent; border: 1px solid var(--ds-border-light);
                color: var(--ds-ink-muted); font-size: 13px; font-weight: var(--ds-fw-semibold);
                cursor: pointer; transition: color .15s, border-color .15s;
            }
            .push-test-btn:hover { color: var(--ds-ink); border-color: var(--ds-ink-soft); }
            .push-test-btn:disabled { opacity: 0.55; cursor: default; }
            .push-test-status { font-size: 13px; color: var(--ds-ink-muted); }
        </style>
        @endpush

        @push('scripts')
        <script>
            (function () {
                var btn = document.getElementById('push-test-btn');
                var status = document.getElementById('push-test-status');
                if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

                var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                btn.hidden = false;

                function say(msg) { status.textContent = msg; }

                // base64url → Uint8Array (applicationServerKey 형식)
                function vapidKey() {
                    var b64 = btn.dataset.vapid.replace(/-/g, '+').replace(/_/g, '/');
                    var pad = '='.repeat((4 - b64.length % 4) % 4);
                    return Uint8Array.from(atob(b64 + pad), function (c) { return c.charCodeAt(0); });
                }

                function api(url, body) {
                    return fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                }

                // 구독이 없으면 만들어서 서버에 등록까지 하고 돌려준다
                function ensureSubscription(reg) {
                    return reg.pushManager.getSubscription().then(function (sub) {
                        if (sub) return sub;
                        return Notification.requestPermission().then(function (perm) {
                            if (perm !== 'granted') throw new Error('denied');
                            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKey() });
                        }).then(function (sub) {
                            var keys = sub.toJSON().keys;
                            return api(@json(route('push.subscribe')), {
                                endpoint: sub.endpoint,
                                keys: { p256dh: keys.p256dh, auth: keys.auth },
                            }).then(function (res) {
                                if (!res.ok) { sub.unsubscribe(); throw new Error('server'); }
                                return sub;
                            });
                        });
                    });
                }

                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    say('보내는 중…');
                    navigator.serviceWorker.ready.then(function (reg) {
                        return ensureSubscription(reg);
                    }).then(function (sub) {
                        return api(@json(route('push.test')), { endpoint: sub.endpoint });
                    }).then(function (res) {
                        return res.json().then(function (json) {
                            var result = json && json.data && json.data.result;
                            say(result === 'sent' ? '발송 완료 — 잠시 후 알림이 떠요 ✅' : '발송 실패 (' + (result || res.status) + ')');
                        });
                    }).catch(function (e) {
                        say(e && e.message === 'denied'
                            ? '알림 권한이 차단돼 있어요 — 브라우저 설정에서 허용해 주세요'
                            : '테스트 실패 — 잠시 후 다시 시도해 주세요');
                    }).finally(function () { btn.disabled = false; });
                });
            })();
        </script>
        @endpush
    @endif
@endsection
