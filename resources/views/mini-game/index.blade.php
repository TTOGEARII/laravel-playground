@extends('layouts.app')

@section('title', 'Mini Game - 게임 플레이랜드')

@section('header')
    <div class="header-nav">
        <a href="/" class="back-button">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
            돌아가기
        </a>
    </div>
    <span class="header-badge">🎮 게임 플레이랜드</span>
    <h1>Mini Game</h1>
    <p>재미있는 미니게임들을 플레이해보세요!</p>
@endsection

@section('content')
    <div class="mg-home-toolbar">
        <button type="button" id="mg-home-rank-open" class="mg-home-rank-open">🏆 전체 랭킹 보기</button>
    </div>

    <section class="games-grid">
        @foreach($games as $game)
        <article class="game-card {{ $game['color'] }} {{ $game['status'] === 'coming-soon' ? 'coming-soon' : '' }}">
            @if($game['status'] === 'coming-soon')
                <span class="status-badge coming-soon">준비중</span>
            @else
                <span class="status-badge">플레이 가능</span>
            @endif
            
            <div class="card-icon">{{ $game['icon'] }}</div>
            <h2 class="card-title">{{ $game['name'] }}</h2>
            <p class="card-description">
                {{ $game['description'] }}
            </p>
            <div class="card-tags">
                @foreach($game['tags'] as $tag)
                    <span class="tag">{{ $tag }}</span>
                @endforeach
            </div>
            @if($game['status'] === 'coming-soon')
                <button class="card-button" disabled>
                    준비중입니다
                </button>
            @else
                <a href="{{ isset($game['route']) ? route($game['route']) : '#' }}" class="card-button">
                    게임 시작
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </a>
            @endif
        </article>
        @endforeach
    </section>

    {{-- 전체 랭킹 팝업 (랭킹 대상 게임 전체) --}}
    <div id="mg-home-rank" class="mg-home-rank" hidden data-url="{{ route('mini-game.rankings') }}">
        <div class="mg-home-rank-box" role="dialog" aria-modal="true" aria-label="전체 게임 랭킹">
            <button type="button" class="mg-home-rank-x" data-close aria-label="닫기">×</button>
            <h2 class="mg-home-rank-h">🏆 전체 랭킹</h2>
            <div id="mg-home-rank-tabs" class="mg-home-rank-tabs"></div>
            <div id="mg-home-rank-body" class="mg-home-rank-body"></div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    .mg-home-toolbar { display: flex; justify-content: flex-end; margin-bottom: 18px; }
    .mg-home-rank-open {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 10px 18px; border-radius: 10px; border: 1px solid #6366f1;
        background: #6366f1; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer;
        transition: filter .15s ease;
    }
    .mg-home-rank-open:hover { filter: brightness(1.1); }

    .mg-home-rank {
        position: fixed; inset: 0; z-index: 9999;
        display: flex; align-items: center; justify-content: center;
        background: rgba(2, 6, 23, 0.78); backdrop-filter: blur(4px); padding: 20px;
        font-family: 'Outfit', 'Noto Sans KR', sans-serif;
    }
    .mg-home-rank[hidden] { display: none; }
    .mg-home-rank-box {
        position: relative; width: 100%; max-width: 440px; max-height: 82vh; overflow: hidden;
        display: flex; flex-direction: column;
        background: #0f172a; border: 1px solid #1e293b; border-radius: 16px;
        padding: 24px; color: #e2e8f0; box-shadow: 0 24px 60px rgba(0,0,0,0.5);
    }
    .mg-home-rank-x {
        position: absolute; top: 14px; right: 16px; background: none; border: none;
        color: #94a3b8; font-size: 26px; line-height: 1; cursor: pointer;
    }
    .mg-home-rank-h { margin: 0 0 16px; font-size: 20px; font-weight: 800; text-align: center; }
    .mg-home-rank-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
    .mg-home-rank-tab {
        padding: 8px 14px; border-radius: 999px; border: 1px solid #334155;
        background: #1e293b; color: #cbd5e1; font-size: 14px; font-weight: 700; cursor: pointer;
    }
    .mg-home-rank-tab.active { background: #6366f1; border-color: #6366f1; color: #fff; }
    .mg-home-rank-body { overflow-y: auto; }
    .mg-home-rank-list { list-style: none; margin: 0; padding: 0; }
    .mg-home-rank-list li { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px; font-size: 14px; }
    .mg-home-rank-list li + li { margin-top: 4px; }
    .mg-home-rank-list .rk { width: 28px; text-align: center; font-weight: 800; color: #94a3b8; flex: none; }
    .mg-home-rank-list li:nth-child(1) .rk { color: #fbbf24; }
    .mg-home-rank-list li:nth-child(2) .rk { color: #cbd5e1; }
    .mg-home-rank-list li:nth-child(3) .rk { color: #d97706; }
    .mg-home-rank-list .nm { flex: 1; color: #e2e8f0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mg-home-rank-list .sc { font-weight: 700; color: #f8fafc; }
    .mg-home-rank-empty { color: #64748b; text-align: center; padding: 24px 0; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const modal = document.getElementById('mg-home-rank');
    const openBtn = document.getElementById('mg-home-rank-open');
    const tabsEl = document.getElementById('mg-home-rank-tabs');
    const bodyEl = document.getElementById('mg-home-rank-body');
    if (!modal || !openBtn) return;

    let games = null; // 캐시

    function renderList(game) {
        if (!game.rankings || game.rankings.length === 0) {
            bodyEl.innerHTML = '<p class="mg-home-rank-empty">아직 등록된 점수가 없습니다.</p>';
            return;
        }
        const ol = document.createElement('ol');
        ol.className = 'mg-home-rank-list';
        for (const row of game.rankings) {
            const li = document.createElement('li');
            const rk = document.createElement('span'); rk.className = 'rk'; rk.textContent = row.rank;
            const nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = row.nickname;
            const sc = document.createElement('span'); sc.className = 'sc'; sc.textContent = Number(row.score).toLocaleString();
            li.append(rk, nm, sc);
            ol.appendChild(li);
        }
        bodyEl.innerHTML = '';
        bodyEl.appendChild(ol);
    }

    function selectTab(index) {
        [...tabsEl.children].forEach((b, i) => b.classList.toggle('active', i === index));
        renderList(games[index]);
    }

    function renderTabs() {
        tabsEl.innerHTML = '';
        games.forEach((game, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mg-home-rank-tab';
            btn.textContent = (game.icon ? game.icon + ' ' : '') + game.name;
            btn.addEventListener('click', () => selectTab(i));
            tabsEl.appendChild(btn);
        });
        if (games.length) selectTab(0);
    }

    async function open() {
        modal.hidden = false;
        if (games === null) {
            bodyEl.innerHTML = '<p class="mg-home-rank-empty">불러오는 중...</p>';
            try {
                const res = await fetch(modal.dataset.url, { headers: { 'Accept': 'application/json' } });
                const json = await res.json();
                games = json.data || [];
            } catch (e) {
                games = null;
                bodyEl.innerHTML = '<p class="mg-home-rank-empty">랭킹을 불러오지 못했습니다.</p>';
                return;
            }
            renderTabs();
        }
    }

    function close() { modal.hidden = true; }

    openBtn.addEventListener('click', open);
    modal.addEventListener('click', (e) => {
        if (e.target === modal || e.target.closest('[data-close]')) close();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
</script>
@endpush
