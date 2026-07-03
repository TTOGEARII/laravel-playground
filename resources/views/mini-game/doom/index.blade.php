@extends('layouts.app')

@section('title', 'DOOM - Mini Game')

@section('body-class', 'doom-page game-immersive')

@section('content')
    <a class="game-exit-btn" id="gameExitBtn" href="{{ route('mini-game.index') }}" hidden
       onclick="return confirm('게임을 종료하고 목록으로 돌아갈까요?')">✕ 게임 종료</a>
    <div class="game-wrapper">
        <div class="game-start-screen" id="startScreen">
            <div class="start-screen-content">
                <h2>🔫 DOOM</h2>
                <p>
                    WebAssembly로 실행되는 <strong>오리지널 DOOM</strong> (셰어웨어 에피소드 1).<br>
                    엔진 prboom + id Software 셰어웨어 WAD. <strong>PC 키보드</strong> 플레이를 권장합니다.
                </p>

                <div class="game-menu" id="gameMenuMain">
                    <button id="startGameBtn" class="game-menu-btn game-menu-btn--primary">게임 시작</button>
                    <button type="button" class="game-menu-btn" data-menu="options">옵션</button>
                    <button type="button" class="game-menu-btn" data-menu="controls">조작법</button>
                    <a href="{{ route('mini-game.index') }}" class="game-menu-btn game-menu-btn--danger"
                       onclick="return confirm('게임 목록으로 돌아갈까요?')">게임 종료</a>
                </div>

                <div class="game-menu-panel" id="game-panel-options" hidden>
                    <h3 class="game-panel-title">옵션</h3>
                    <label class="game-opt-row"><span>사운드</span><input type="checkbox" id="mgOptSound"></label>
                    <label class="game-opt-row"><span>소리 크기</span><input type="range" id="mgOptVolume" min="0" max="100" value="70"></label>
                    <p class="game-opt-note">※ 사운드는 게임 내에서 조절합니다.</p>
                    <button type="button" class="game-menu-back">← 뒤로</button>
                </div>

                <div class="game-menu-panel" id="game-panel-controls" hidden>
                    <h3 class="game-panel-title">조작 방법 (PC 키보드 / 마우스)</h3>
                    <ul class="game-ctrl-list">
                        <li><kbd>W</kbd> <kbd>S</kbd> 전진/후진 · <kbd>Q</kbd> <kbd>E</kbd> 회전 · <kbd>A</kbd> <kbd>D</kbd> 좌우 이동(스트레이프)</li>
                        <li><strong>마우스 좌클릭</strong> 발사 · <kbd>Space</kbd> 문 열기/사용 · <kbd>Shift</kbd> 달리기 · <kbd>Alt</kbd> 스트레이프 전환</li>
                        <li><kbd>1</kbd>~<kbd>7</kbd> 무기 교체 · 캔버스를 클릭하면 마우스로 시점을 돌릴 수 있습니다(포인터 락)</li>
                        <li class="mobile-only">모바일: 하단 가상 버튼(▲▼ 전후 · ◀▶ 회전 · 발사 · 사용 · ↵ 확인 · ESC 메뉴)으로 플레이</li>
                        <li>오리지널 DOOM 셰어웨어(에피소드 1: Knee-Deep in the Dead) 데이터로 동작합니다.</li>
                    </ul>
                    <button type="button" class="game-menu-back">← 뒤로</button>
                </div>

                <p class="doom-hint-mobile">※ 모바일은 화면 하단 가상 버튼으로 조작합니다.</p>
            </div>
        </div>

        <div id="game-container" style="display: none;">
            <canvas id="doom" tabindex="0" oncontextmenu="event.preventDefault()"></canvas>
            <div class="doom-loader" id="doomLoader">
                <span class="doom-loader-title">DOOM 로딩 중...</span>
                <span class="doom-loader-progress" id="doomProgress">0%</span>
            </div>
            <button class="doom-fullscreen" id="doomFullscreen">⛶ 전체화면</button>
            <button class="doom-crosshair-toggle" id="doomCrosshairToggle">＋ 조준선</button>

            {{-- 화면 중앙(=발사 방향) 조준선 오버레이. 원본 DOOM에는 없어 직접 얹는다. --}}
            <div class="doom-crosshair" id="doomCrosshair"></div>

            {{-- 모바일 전용 가상 컨트롤 (JS가 터치 기기일 때만 표시) --}}
            <div class="doom-controls" id="doomControls" aria-hidden="true">
                <div class="doom-dpad">
                    <button class="doom-btn up" data-key="87" aria-label="전진(W)">▲</button>
                    <button class="doom-btn left" data-key="81" aria-label="왼쪽 회전(Q)">◀</button>
                    <button class="doom-btn down" data-key="83" aria-label="후진(S)">▼</button>
                    <button class="doom-btn right" data-key="69" aria-label="오른쪽 회전(E)">▶</button>
                </div>
                <div class="doom-actions">
                    <button class="doom-btn fire" data-act="fire" aria-label="발사(마우스)">발사</button>
                    <button class="doom-btn use" data-key="32" aria-label="사용/문(Space)">사용</button>
                    <button class="doom-btn enter" data-key="13" aria-label="확인(Enter)">↵</button>
                    <button class="doom-btn esc" data-key="27" aria-label="메뉴(Esc)">ESC</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
// ============================================================================
// DOOM (prboom → WebAssembly) 로더
// 에셋: /public/doom/{doom1.js, doom1.wasm, doom1.data}  (UstymUkhman/webDOOM 기반)
// emscripten Module 을 먼저 구성한 뒤 doom1.js 를 주입해 실행한다.
// ============================================================================
(function () {
    var loaded = false;

    function updateStatus(text) {
        var progressEl = document.getElementById('doomProgress');
        var loaderEl = document.getElementById('doomLoader');
        if (!progressEl) return;

        if (!text) { // emscripten 은 모든 의존성 로드 후 빈 문자열로 호출
            finishLoading();
            return;
        }
        // "Downloading data... (123/456)" 형태에서 퍼센트 추출
        var m = text.match(/\((\d+(\.\d+)?)\/(\d+)\)/);
        if (m) {
            var pct = (parseFloat(m[1]) / parseFloat(m[3])) * 100;
            progressEl.textContent = pct.toFixed(0) + '%';
            if (pct >= 100) setTimeout(finishLoading, 400);
        } else {
            progressEl.textContent = text;
        }
    }

    function finishLoading() {
        var loaderEl = document.getElementById('doomLoader');
        var canvas = document.getElementById('doom');
        if (loaderEl) loaderEl.classList.add('is-hidden');
        if (canvas) {
            canvas.classList.add('is-ready');
            canvas.focus();
            // 일부 빌드는 첫 입력으로 시작되므로 클릭 이벤트를 흘려준다
            canvas.dispatchEvent(new MouseEvent('mousedown'));
        }
        // 캔버스 비디오 모드 확정 타이밍이 들쭉날쭉해 몇 번 더 맞춘다
        placeCrosshair();
        [200, 600, 1500].forEach(function (t) { setTimeout(placeCrosshair, t); });
    }

    // prboom(SDL)에 가상 버튼 입력을 합성 KeyboardEvent 로 전달한다.
    // 이 빌드(webDOOM) 키맵: W/S 전후·Q/E 회전·Space 사용·발사는 마우스 좌클릭.
    // 게임플레이 입력(makeCEvent)은 key/code 도 읽으므로 keyCode + key + code 를 모두 채운다.
    var KEY_INFO = {
        87: { key: 'w', code: 'KeyW' },   // 전진
        83: { key: 's', code: 'KeyS' },   // 후진
        81: { key: 'q', code: 'KeyQ' },   // 좌회전
        69: { key: 'e', code: 'KeyE' },   // 우회전
        65: { key: 'a', code: 'KeyA' },   // 좌측 이동(스트레이프)
        68: { key: 'd', code: 'KeyD' },   // 우측 이동(스트레이프)
        32: { key: ' ', code: 'Space' },  // 사용
        13: { key: 'Enter', code: 'Enter' },
        27: { key: 'Escape', code: 'Escape' },
    };
    function dispatchKey(keyCode, isDown) {
        var info = KEY_INFO[keyCode] || {};
        var e = new KeyboardEvent(isDown ? 'keydown' : 'keyup', {
            bubbles: true, cancelable: true, key: info.key, code: info.code, location: 0,
        });
        Object.defineProperty(e, 'keyCode', { get: function () { return keyCode; } });
        Object.defineProperty(e, 'which', { get: function () { return keyCode; } });
        document.dispatchEvent(e);
    }

    // 발사는 키가 아니라 캔버스 마우스 좌클릭(button 0)으로 동작한다.
    function dispatchFire(isDown) {
        var canvas = document.getElementById('doom');
        if (!canvas) return;
        var rect = canvas.getBoundingClientRect();
        var ev = new MouseEvent(isDown ? 'mousedown' : 'mouseup', {
            bubbles: true, cancelable: true, button: 0, buttons: isDown ? 1 : 0,
            clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2,
        });
        canvas.dispatchEvent(ev);
    }

    function isTouchDevice() {
        return ('ontouchstart' in window) || navigator.maxTouchPoints > 0;
    }

    // 조준선: 캔버스(=게임 화면) 기준 수평 중앙, 3D 뷰 중앙(상태바 고려해 약 42%)에 배치
    var crosshairOn = true;
    function placeCrosshair() {
        var ch = document.getElementById('doomCrosshair');
        var canvas = document.getElementById('doom');
        var cont = document.getElementById('game-container');
        if (!ch || !canvas || !cont) return;
        if (!crosshairOn || !canvas.classList.contains('is-ready')) { ch.style.display = 'none'; return; }
        var cr = canvas.getBoundingClientRect();
        var pr = cont.getBoundingClientRect();
        if (!cr.width || !cr.height) { ch.style.display = 'none'; return; }
        ch.style.left = (cr.left - pr.left + cr.width / 2) + 'px';
        ch.style.top = (cr.top - pr.top + cr.height * 0.42) + 'px';
        ch.style.display = 'block';
    }

    function bindDoomControls() {
        var controls = document.getElementById('doomControls');
        if (!controls || !isTouchDevice()) return;

        controls.style.display = 'flex';
        controls.setAttribute('aria-hidden', 'false');
        document.getElementById('game-container').classList.add('has-touch-controls');

        var canvas = document.getElementById('doom');
        controls.querySelectorAll('.doom-btn').forEach(function (btn) {
            btn.tabIndex = -1; // 버튼이 포커스를 가져가지 않도록
            var act = btn.dataset.act;
            var code = parseInt(btn.dataset.key, 10);
            var pressed = false;
            var send = function (isDown) { if (act === 'fire') dispatchFire(isDown); else dispatchKey(code, isDown); };
            // 게임플레이 입력은 캔버스가 포커스를 가져야 처리되므로 누를 때마다 포커스를 보장한다.
            var down = function (ev) { ev.preventDefault(); if (canvas) canvas.focus(); if (pressed) return; pressed = true; btn.classList.add('is-down'); send(true); };
            var up = function (ev) { ev.preventDefault(); if (!pressed) return; pressed = false; btn.classList.remove('is-down'); send(false); };
            btn.addEventListener('touchstart', down, { passive: false });
            btn.addEventListener('touchend', up, { passive: false });
            btn.addEventListener('touchcancel', up, { passive: false });
            // 데스크톱 마우스로도 테스트 가능
            btn.addEventListener('mousedown', down);
            btn.addEventListener('mouseup', up);
            btn.addEventListener('mouseleave', up);
        });
    }

    function startDoom() {
        if (loaded) return;
        loaded = true;

        var canvas = document.getElementById('doom');

        window.Module = {
            canvas: canvas,
            arguments: [],                       // WAD(/doom1.wad, /prboom.wad)는 루트에서 자동 탐색
            locateFile: function (path) { return '/doom/' + path; }, // wasm/data 위치
            print: function (t) { console.log('[doom]', t); },
            printErr: function (t) { console.warn('[doom]', t); },
            setStatus: updateStatus,
            monitorRunDependencies: function () {},
            onRuntimeInitialized: function () { setTimeout(finishLoading, 800); },
        };

        canvas.addEventListener('webglcontextlost', function (e) {
            alert('WebGL 컨텍스트가 손실되었습니다. 페이지를 새로고침하세요.');
            e.preventDefault();
        }, false);
        // 마우스 시점 조작용 포인터 락 (자동화/비활성 문서에서의 거부는 무시)
        canvas.addEventListener('click', function () {
            if (!canvas.requestPointerLock) return;
            var p = canvas.requestPointerLock();
            if (p && typeof p.catch === 'function') p.catch(function () {});
        });

        bindDoomControls();

        var s = document.createElement('script');
        s.src = '/doom/doom1.js';
        s.onerror = function () { updateStatus('로드 실패: /doom/doom1.js 를 찾을 수 없습니다.'); };
        document.body.appendChild(s);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var startBtn = document.getElementById('startGameBtn');
        var fsBtn = document.getElementById('doomFullscreen');

        if (startBtn) {
            startBtn.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('startScreen').style.display = 'none';
                document.getElementById('game-container').style.display = 'flex';
                this.disabled = true;
                startDoom();
            });
        }
        if (fsBtn) {
            fsBtn.addEventListener('click', function () {
                if (window.Module && typeof window.Module.requestFullscreen === 'function') {
                    window.Module.requestFullscreen(false, true);
                } else {
                    var el = document.getElementById('game-container');
                    if (el && el.requestFullscreen) el.requestFullscreen();
                }
                setTimeout(placeCrosshair, 300);
            });
        }

        var chBtn = document.getElementById('doomCrosshairToggle');
        if (chBtn) {
            chBtn.addEventListener('click', function () {
                crosshairOn = !crosshairOn;
                chBtn.classList.toggle('is-off', !crosshairOn);
                placeCrosshair();
            });
        }

        window.addEventListener('resize', placeCrosshair);
        document.addEventListener('fullscreenchange', function () { setTimeout(placeCrosshair, 300); });
    });
})();
    </script>
    <script>
        // 시작화면 메뉴 네비게이션(옵션/조작법 패널) + 옵션 저장 + 게임 시작 시 종료버튼 노출
        (function () {
            document.querySelectorAll('.game-menu-btn[data-menu]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById('gameMenuMain').hidden = true;
                    var p = document.getElementById('game-panel-' + btn.dataset.menu);
                    if (p) p.hidden = false;
                });
            });
            document.querySelectorAll('.game-menu-back').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.game-menu-panel').forEach(function (p) { p.hidden = true; });
                    document.getElementById('gameMenuMain').hidden = false;
                });
            });
            var snd = document.getElementById('mgOptSound');
            var vol = document.getElementById('mgOptVolume');
            if (snd) { snd.checked = localStorage.getItem('mg_sound') !== '0'; snd.addEventListener('change', function () { localStorage.setItem('mg_sound', snd.checked ? '1' : '0'); }); }
            if (vol) { vol.value = localStorage.getItem('mg_volume') || '70'; vol.addEventListener('input', function () { localStorage.setItem('mg_volume', vol.value); }); }
            var startBtn = document.getElementById('startGameBtn');
            var exitBtn = document.getElementById('gameExitBtn');
            if (startBtn && exitBtn) startBtn.addEventListener('click', function () { exitBtn.hidden = false; });
        })();
    </script>
    @endpush
@endsection
