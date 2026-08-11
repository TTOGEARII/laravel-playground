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
        display: flex; justify-content: center;
        /* 내용이 뷰포트보다 높으면(모바일) 상하가 잘리지 않도록 오버레이가 스크롤되게 한다 */
        overflow-y: auto; -webkit-overflow-scrolling: touch;
        background: color-mix(in srgb, var(--ink) 74%, transparent); backdrop-filter: blur(5px);
        padding: 20px;
    }
    .mg-rank-overlay[hidden] { display: none; }
    .mg-rank-modal {
        width: 100%; max-width: 400px;
        margin: auto; /* 공간 있으면 중앙, 크면 스크롤(align-items:center 의 잘림 회피) */
        background: var(--ink2); border: 1px solid var(--line); border-radius: var(--r-l);
        padding: 28px 26px; color: var(--tx); box-shadow: var(--shadow-lift);
        text-align: center;
    }
    .mg-rank-title { margin: 0; font-family: var(--disp); font-size: 28px; color: var(--accent); }
    .mg-rank-score { margin: 8px 0 18px; font-size: 15px; color: var(--tx2); }
    .mg-rank-score strong { color: var(--hd); font-family: var(--disp); font-size: 24px; }
    .mg-rank-who { font-size: 14px; color: var(--tx2); margin: 0 0 16px; }
    .mg-rank-who strong { color: var(--accent2); }
    .mg-rank-label { display: block; text-align: left; font-family: var(--label); font-weight: 700; font-size: 12px; color: var(--tx2); margin-bottom: 6px; }
    .mg-rank-input {
        width: 100%; box-sizing: border-box; padding: 12px 14px; margin-bottom: 14px;
        background: var(--chip-bg); border: 1px solid var(--chip-bd); border-radius: var(--r-m);
        color: var(--hd); font-size: 15px; font-family: var(--body);
    }
    .mg-rank-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--chip-pink-bg); }
    .mg-rank-err { color: var(--ds-negative); font-size: 13px; margin: 0 0 12px; }
    .mg-rank-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 6px; }
    .mg-rank-btn {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 13px 18px; border-radius: var(--r-pill); border: 1px solid var(--chip-bd);
        background: var(--chip-bg); color: var(--hd); font-family: var(--label); font-size: 14px; font-weight: 700;
        cursor: pointer; text-decoration: none; transition: .2s ease;
    }
    .mg-rank-btn:hover { transform: translateY(-2px); }
    .mg-rank-btn--primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); box-shadow: var(--shadow-btn); }
    .mg-rank-btn--link { background: transparent; border-color: transparent; color: var(--tx3); }
    .mg-rank-myrank { font-size: 15px; color: var(--tx2); margin: 0 0 14px; }
    .mg-rank-myrank strong { color: var(--accent3); font-family: var(--disp); font-size: 22px; }
    .mg-rank-list { list-style: none; margin: 0 0 18px; padding: 0; text-align: left; max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
    .mg-rank-list li {
        display: grid; grid-template-columns: 40px 1fr auto; align-items: center; gap: 10px;
        padding: 11px 14px; border-radius: var(--r-m); background: var(--chip-bg); border: 1px solid var(--chip-bd);
        font-size: 14px;
    }
    .mg-rank-list .mg-rank-rk { font-family: var(--disp); font-weight: 700; color: var(--tx3); text-align: center; }
    .mg-rank-list .mg-rank-nm { color: var(--hd); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mg-rank-list .mg-rank-sc { font-family: var(--label); font-weight: 700; color: var(--hd); }
    .mg-rank-list li.is-me { background: var(--chip-pink-bg); border-color: var(--accent); }
    .mg-rank-list li:nth-child(1) .mg-rank-rk { color: var(--accent3); }
    .mg-rank-list li:nth-child(2) .mg-rank-rk { color: var(--accent2); }
    .mg-rank-list li:nth-child(3) .mg-rank-rk { color: var(--accent); }
    .mg-rank-list .mg-rank-empty { color: var(--tx3); padding: 12px; text-align: center; }
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
