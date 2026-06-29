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
