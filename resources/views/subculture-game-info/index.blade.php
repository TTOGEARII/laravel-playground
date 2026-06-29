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
@endsection

@section('content')
    <div class="sgi-page">
        <nav class="sgi-tabs">
            <a href="{{ route('subculture-game-info.index') }}"
               class="sgi-tab {{ $selected === null ? 'is-active' : '' }}">전체</a>
            @foreach ($games as $game)
                <a href="{{ route('subculture-game-info.index', ['game' => $game->slug]) }}"
                   class="sgi-tab {{ $selected === $game->slug ? 'is-active' : '' }}">
                    <span class="sgi-tab-icon">{{ $game->icon }}</span> {{ $game->name }}
                </a>
            @endforeach
        </nav>

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

                        if (IS_LOGGED_IN) {
                            btn.disabled = true;
                            syncServer(id, on).then(function (ok) {
                                btn.disabled = false;
                                if (!ok) { // 롤백
                                    if (on) redeemed.delete(id); else redeemed.add(id);
                                    paint(id, !on);
                                }
                            });
                        } else {
                            saveLocal(Array.from(redeemed));
                        }
                    });
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
@endsection
