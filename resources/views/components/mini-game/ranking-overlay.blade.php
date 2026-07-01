@props(['game'])

@php
    $isLoggedIn = auth()->check();
    $userNickname = $isLoggedIn ? auth()->user()->name : null;
@endphp

{{--
    미니게임 공통 게임오버 랭킹 오버레이.
    사용법: 게임 뷰에 <x-mini-game.ranking-overlay game="tetris" /> 를 넣고,
    게임오버 시 window.MiniGameRanking.show(최종점수) 를 호출하면 된다.
--}}
<div id="mg-rank-overlay" class="mg-rank-overlay" hidden
    data-game="{{ $game }}"
    data-logged-in="{{ $isLoggedIn ? '1' : '0' }}"
    data-nickname="{{ $userNickname }}"
    data-store-url="{{ route('mini-game.scores.store') }}">
    <div class="mg-rank-modal" role="dialog" aria-modal="true" aria-label="게임 결과 및 랭킹">
        <h2 class="mg-rank-title">게임 오버</h2>
        <p class="mg-rank-score">점수 <strong id="mg-rank-final">0</strong></p>

        {{-- 1단계: 등록 --}}
        <div id="mg-rank-form">
            @if ($isLoggedIn)
                <p class="mg-rank-who">닉네임 <strong>{{ $userNickname }}</strong> (으)로 등록됩니다.</p>
            @else
                <label class="mg-rank-label" for="mg-rank-nick">닉네임</label>
                <input id="mg-rank-nick" class="mg-rank-input" type="text" maxlength="20"
                    placeholder="닉네임을 입력하세요" autocomplete="off">
            @endif
            <p id="mg-rank-error" class="mg-rank-err" hidden></p>
            <div class="mg-rank-actions">
                <button type="button" class="mg-rank-btn mg-rank-btn--primary" data-mg="submit">랭킹 등록</button>
                <button type="button" class="mg-rank-btn" data-mg="restart">등록 없이 다시하기</button>
            </div>
        </div>

        {{-- 2단계: 결과(랭킹) --}}
        <div id="mg-rank-result" hidden>
            <p class="mg-rank-myrank">내 순위 <strong id="mg-rank-myrank-num">-</strong>위</p>
            <ol id="mg-rank-list" class="mg-rank-list"></ol>
            <div class="mg-rank-actions">
                <button type="button" class="mg-rank-btn mg-rank-btn--primary" data-mg="restart">다시하기</button>
                <a href="{{ route('mini-game.index') }}" class="mg-rank-btn mg-rank-btn--link">홈으로</a>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .mg-rank-overlay {
        position: fixed; inset: 0; z-index: 9999;
        display: flex; align-items: center; justify-content: center;
        background: rgba(2, 6, 23, 0.78); backdrop-filter: blur(4px);
        padding: 20px; font-family: 'Outfit', 'Noto Sans KR', sans-serif;
    }
    .mg-rank-overlay[hidden] { display: none; }
    .mg-rank-modal {
        width: 100%; max-width: 380px;
        background: #0f172a; border: 1px solid #1e293b; border-radius: 16px;
        padding: 26px 24px; color: #e2e8f0; box-shadow: 0 24px 60px rgba(0,0,0,0.5);
        text-align: center;
    }
    .mg-rank-title { margin: 0; font-size: 26px; font-weight: 800; color: #f87171; }
    .mg-rank-score { margin: 8px 0 18px; font-size: 15px; color: #94a3b8; }
    .mg-rank-score strong { color: #f8fafc; font-size: 22px; }
    .mg-rank-who { font-size: 14px; color: #cbd5e1; margin: 0 0 16px; }
    .mg-rank-who strong { color: #5eead4; }
    .mg-rank-label { display: block; text-align: left; font-size: 13px; color: #94a3b8; margin-bottom: 6px; }
    .mg-rank-input {
        width: 100%; box-sizing: border-box; padding: 11px 12px; margin-bottom: 14px;
        background: #1e293b; border: 1px solid #334155; border-radius: 10px;
        color: #f1f5f9; font-size: 15px;
    }
    .mg-rank-input:focus { outline: none; border-color: #6366f1; }
    .mg-rank-err { color: #fca5a5; font-size: 13px; margin: 0 0 12px; }
    .mg-rank-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 6px; }
    .mg-rank-btn {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 12px 16px; border-radius: 10px; border: 1px solid #334155;
        background: #1e293b; color: #e2e8f0; font-size: 15px; font-weight: 700;
        cursor: pointer; text-decoration: none; transition: filter .15s ease;
    }
    .mg-rank-btn:hover { filter: brightness(1.15); }
    .mg-rank-btn--primary { background: #6366f1; border-color: #6366f1; color: #fff; }
    .mg-rank-btn--link { background: transparent; border-color: transparent; color: #94a3b8; }
    .mg-rank-myrank { font-size: 15px; color: #cbd5e1; margin: 0 0 14px; }
    .mg-rank-myrank strong { color: #fbbf24; font-size: 20px; }
    .mg-rank-list { list-style: none; margin: 0 0 18px; padding: 0; text-align: left; max-height: 320px; overflow-y: auto; }
    .mg-rank-list li {
        display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 8px;
        font-size: 14px;
    }
    .mg-rank-list li + li { margin-top: 4px; }
    .mg-rank-list .mg-rank-rk { width: 28px; font-weight: 800; color: #94a3b8; text-align: center; flex: none; }
    .mg-rank-list .mg-rank-nm { flex: 1; color: #e2e8f0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mg-rank-list .mg-rank-sc { font-weight: 700; color: #f8fafc; }
    .mg-rank-list li.is-me { background: rgba(99,102,241,0.18); border: 1px solid rgba(99,102,241,0.5); }
    .mg-rank-list li:nth-child(1) .mg-rank-rk { color: #fbbf24; }
    .mg-rank-list li:nth-child(2) .mg-rank-rk { color: #cbd5e1; }
    .mg-rank-list li:nth-child(3) .mg-rank-rk { color: #d97706; }
    .mg-rank-list .mg-rank-empty { color: #64748b; padding: 12px; text-align: center; }
</style>
@endpush

@push('scripts')
<script>
// 미니게임 공통 랭킹 오버레이 컨트롤러. window.MiniGameRanking.show(score) 로 띄운다.
window.MiniGameRanking = (function () {
    const overlay = document.getElementById('mg-rank-overlay');
    if (!overlay) return { show() {} };

    const formStep = document.getElementById('mg-rank-form');
    const resultStep = document.getElementById('mg-rank-result');
    const finalEl = document.getElementById('mg-rank-final');
    const errEl = document.getElementById('mg-rank-error');
    const nickInput = document.getElementById('mg-rank-nick'); // 게스트만 존재
    const listEl = document.getElementById('mg-rank-list');
    const myRankEl = document.getElementById('mg-rank-myrank-num');

    const gameKey = overlay.dataset.game;
    const loggedIn = overlay.dataset.loggedIn === '1';
    const fixedNickname = overlay.dataset.nickname || '';
    const storeUrl = overlay.dataset.storeUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let currentScore = 0;
    let submitting = false;

    function show(score) {
        currentScore = Math.max(0, Math.floor(Number(score) || 0));
        finalEl.textContent = currentScore.toLocaleString();
        errEl.hidden = true;
        formStep.hidden = false;
        resultStep.hidden = true;
        overlay.hidden = false;
        if (nickInput) setTimeout(() => nickInput.focus(), 50);
    }

    function restart() {
        window.location.reload();
    }

    async function submit() {
        if (submitting) return;
        const nickname = loggedIn ? fixedNickname : (nickInput?.value.trim() || '');
        submitting = true;
        errEl.hidden = true;
        try {
            const res = await fetch(storeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ game: gameKey, score: currentScore, nickname }),
            });
            if (!res.ok) throw new Error('요청 실패 (' + res.status + ')');
            const { data } = await res.json();
            renderResult(data);
        } catch (e) {
            errEl.textContent = '점수 등록에 실패했습니다. 잠시 후 다시 시도해 주세요.';
            errEl.hidden = false;
        } finally {
            submitting = false;
        }
    }

    function renderResult(data) {
        myRankEl.textContent = (data.rank ?? '-').toLocaleString();
        listEl.innerHTML = '';
        if (!data.rankings || data.rankings.length === 0) {
            const li = document.createElement('li');
            li.innerHTML = '<span class="mg-rank-empty">아직 랭킹이 없습니다.</span>';
            listEl.appendChild(li);
        } else {
            for (const row of data.rankings) {
                const li = document.createElement('li');
                if (row.id === data.score_id) li.classList.add('is-me');
                const rk = document.createElement('span'); rk.className = 'mg-rank-rk'; rk.textContent = row.rank;
                const nm = document.createElement('span'); nm.className = 'mg-rank-nm'; nm.textContent = row.nickname;
                const sc = document.createElement('span'); sc.className = 'mg-rank-sc'; sc.textContent = Number(row.score).toLocaleString();
                li.append(rk, nm, sc);
                listEl.appendChild(li);
            }
        }
        formStep.hidden = true;
        resultStep.hidden = false;
    }

    overlay.addEventListener('click', (e) => {
        const act = e.target.closest('[data-mg]')?.dataset.mg;
        if (act === 'submit') submit();
        else if (act === 'restart') restart();
    });
    nickInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });

    return { show };
})();
</script>
@endpush
