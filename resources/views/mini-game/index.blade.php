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
    <section class="shell phero">
        <a href="/" class="back">← 메인으로</a>
        <span class="tag">🎮 게임 플레이랜드</span>
        <h1>Mini Game</h1>
        <p>재미있는 미니게임들을 플레이해보세요!</p>
    </section>

    @php
        $list = collect($games)->values();
        $featured = $list->first();
        $rest = $list->slice(1)->values();
        $tones = ['pink', 'cyan', 'gold'];
    @endphp

    <section class="shell stack g3" style="padding-top:0">
        {{-- 필터 바: 전체 개수 칩 + 전체 랭킹 팝업 트리거 --}}
        <div class="row gfilter" style="justify-content:space-between">
            <div class="chips">
                <span class="chip on">전체 {{ $list->count() }}</span>
            </div>
            <button type="button" id="mg-home-rank-open" class="btn btn-soft">🏆 전체 랭킹</button>
        </div>

        @if ($featured)
            {{-- 대표(피처) 카드 + 사이드 카드 슬라이드 --}}
            <div class="gfeature">
                <x-mini-game.game-card :game="$featured" :index="0" tone="pink" featured />
                @if ($rest->isNotEmpty())
                    <div class="gside slide">
                        @foreach ($rest as $i => $game)
                            <x-mini-game.game-card :game="$game" :index="$i + 1" :tone="$tones[($i + 1) % count($tones)]" />
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <div class="empty">아직 등록된 게임이 없습니다.</div>
        @endif
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
    .mg-home-rank {
        position: fixed; inset: 0; z-index: 9999;
        display: flex; align-items: center; justify-content: center;
        background: color-mix(in srgb, var(--ink) 74%, transparent); backdrop-filter: blur(5px); padding: 20px;
    }
    .mg-home-rank[hidden] { display: none; }
    .mg-home-rank-box {
        position: relative; width: 100%; max-width: 460px; max-height: 82vh; overflow: hidden;
        display: flex; flex-direction: column;
        background: var(--ink2); border: 1px solid var(--line); border-radius: var(--r-l);
        padding: 26px; color: var(--tx); box-shadow: var(--shadow-lift);
    }
    .mg-home-rank-x {
        position: absolute; top: 14px; right: 16px; background: none; border: none;
        color: var(--tx3); font-size: 26px; line-height: 1; cursor: pointer;
    }
    .mg-home-rank-x:hover { color: var(--accent); }
    .mg-home-rank-h { margin: 0 0 16px; font-family: var(--disp); font-size: 22px; text-align: center; color: var(--hd); }
    .mg-home-rank-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
    .mg-home-rank-tab {
        padding: 8px 14px; border-radius: var(--r-pill); border: 1px solid var(--chip-bd);
        background: var(--chip-bg); color: var(--tx2); font-family: var(--label); font-weight: 700; font-size: 13px; cursor: pointer; transition: .2s ease;
    }
    .mg-home-rank-tab:hover { color: var(--accent); }
    .mg-home-rank-tab.active { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
    .mg-home-rank-body { overflow-y: auto; }
    .mg-home-rank-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .mg-home-rank-list li {
        display: grid; grid-template-columns: 44px 1fr auto; align-items: center; gap: 12px;
        padding: 12px 16px; border-radius: var(--r-m); background: var(--chip-bg); border: 1px solid var(--chip-bd); font-size: 14px;
    }
    .mg-home-rank-list .rk { text-align: center; font-family: var(--disp); font-weight: 700; font-size: 17px; color: var(--tx3); }
    .mg-home-rank-list li:nth-child(1) .rk { color: var(--accent3); }
    .mg-home-rank-list li:nth-child(2) .rk { color: var(--accent2); }
    .mg-home-rank-list li:nth-child(3) .rk { color: var(--accent); }
    .mg-home-rank-list .nm { color: var(--hd); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mg-home-rank-list .sc { font-family: var(--label); font-weight: 700; color: var(--hd); }
    .mg-home-rank-empty { color: var(--tx3); text-align: center; padding: 24px 0; }
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
