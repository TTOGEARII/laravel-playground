@extends('layouts.app')

@section('title', '테트리스 - Mini Game')

@section('body-class', 'tetris-page game-immersive')

@section('content')
    <a class="game-exit-btn" id="gameExitBtn" href="{{ route('mini-game.index') }}" hidden
       onclick="return confirm('게임을 종료하고 목록으로 돌아갈까요?')">✕ 게임 종료</a>
    <div class="game-wrapper">
        <div class="game-start-screen" id="startScreen">
            <div class="start-screen-content">
                <h2>🟦 테트리스</h2>
                <p>블록을 쌓아 줄을 지우고 점수를 올려라!<br>홀드·소프트드롭·티스핀(2배 점수)까지.</p>

                <div class="game-menu" id="gameMenuMain">
                    <button id="startGameBtn" class="game-menu-btn game-menu-btn--primary">게임 시작</button>
                    <a href="{{ route('mini-game.tetris.versus') }}" class="game-menu-btn game-menu-btn--versus">👥 멀티 대전 <small>실시간 1:1</small></a>
                    <button type="button" class="game-menu-btn" data-menu="options">옵션</button>
                    <button type="button" class="game-menu-btn" data-menu="controls">조작법</button>
                    <a href="{{ route('mini-game.index') }}" class="game-menu-btn game-menu-btn--danger"
                       onclick="return confirm('게임 목록으로 돌아갈까요?')">게임 종료</a>
                </div>

                <div class="game-menu-panel" id="game-panel-options" hidden>
                    <h3 class="game-panel-title">옵션</h3>
                    <label class="game-opt-row"><span>사운드</span><input type="checkbox" id="mgOptSound"></label>
                    <label class="game-opt-row"><span>소리 크기</span><input type="range" id="mgOptVolume" min="0" max="100" value="70"></label>
                    <p class="game-opt-note">※ 사운드는 추후 추가 예정입니다.</p>
                    <button type="button" class="game-menu-back">← 뒤로</button>
                </div>

                <div class="game-menu-panel" id="game-panel-controls" hidden>
                    <h3 class="game-panel-title">조작법</h3>
                    <ul class="game-ctrl-list">
                        <li class="desktop-only"><kbd>←</kbd> <kbd>→</kbd> 이동 · <kbd>↓</kbd> 소프트드롭(가속) · <kbd>Space</kbd> 하드드롭</li>
                        <li class="desktop-only"><kbd>↑</kbd> / <kbd>X</kbd> 시계방향 회전 · <kbd>Z</kbd> 반시계방향 회전</li>
                        <li class="desktop-only"><kbd>C</kbd> 또는 <kbd>Shift</kbd> 홀드(조각 보관/교체, 한 조각당 1회)</li>
                        <li class="mobile-only">화면 하단 가상 버튼: ◀ ▼ ▶ 이동/소프트드롭 · ↻ 회전 · HOLD 홀드 · ⤓ 하드드롭</li>
                        <li>가로 한 줄을 가득 채우면 줄이 사라지고 점수를 얻습니다 (1·2·3·4줄 = 100·300·500·800 × 레벨)</li>
                        <li><strong>티스핀</strong>(T조각을 회전으로 끼워 넣어 줄 제거)에 성공하면 <strong>점수 2배</strong>!</li>
                        <li>10줄마다 레벨이 오르고 블록이 더 빠르게 떨어집니다.</li>
                    </ul>
                    <button type="button" class="game-menu-back">← 뒤로</button>
                </div>
            </div>
        </div>
        <div id="game-container" style="display: none;" tabindex="0"></div>

        {{-- 모바일 전용 반투명 가상 키패드 (JS가 터치 기기일 때만 표시) --}}
        <div class="touch-controls" id="touchControls" aria-hidden="true">
            <div class="tc-group">
                <button class="tc-btn" data-act="left" aria-label="왼쪽 이동">◀</button>
                <button class="tc-btn" data-act="softdrop" aria-label="소프트드롭">▼</button>
                <button class="tc-btn" data-act="right" aria-label="오른쪽 이동">▶</button>
            </div>
            <div class="tc-group">
                <button class="tc-btn" data-act="rotate" aria-label="회전">↻</button>
                <button class="tc-btn tc-wide" data-act="hold" aria-label="홀드">HOLD</button>
                <button class="tc-btn tc-hard" data-act="harddrop" aria-label="하드드롭">⤓</button>
            </div>
        </div>
    </div>

    <x-mini-game.ranking-overlay game="tetris" />

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/phaser@3.80.1/dist/phaser.min.js"></script>
    <script>
// ============================================================================
// 테트리스 (Phaser 3) — 반응형(풀스크린) + 모바일 가상 키패드
// 기능: 7-bag 랜덤, 홀드, 소프트/하드드롭 가속, 점수, 티스핀 2배 점수
// ============================================================================

const COLS = 10;
const ROWS = 20;

// --- 타이밍 상수(ms) ---
const SOFT_INTERVAL = 30;        // 소프트드롭 낙하 간격
const LOCK_DELAY = 500;          // 바닥에 닿은 뒤 고정까지 지연
const DAS = 150;                 // 좌우 첫 반복까지 지연
const ARR = 40;                  // 좌우 반복 간격

const SHAPES = {
    I: { color: 0x22d3ee, cells: [[0,0,0,0],[1,1,1,1],[0,0,0,0],[0,0,0,0]] },
    O: { color: 0xfacc15, cells: [[1,1],[1,1]] },
    T: { color: 0xa855f7, cells: [[0,1,0],[1,1,1],[0,0,0]] },
    S: { color: 0x4ade80, cells: [[0,1,1],[1,1,0],[0,0,0]] },
    Z: { color: 0xf87171, cells: [[1,1,0],[0,1,1],[0,0,0]] },
    J: { color: 0x60a5fa, cells: [[1,0,0],[1,1,1],[0,0,0]] },
    L: { color: 0xfb923c, cells: [[0,0,1],[1,1,1],[0,0,0]] },
};
const LINE_SCORE = { 1: 100, 2: 300, 3: 500, 4: 800 };

const isTouchDevice = () => ('ontouchstart' in window) || navigator.maxTouchPoints > 0;
const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

function rotateCW(m) {
    const N = m.length;
    const r = Array.from({ length: N }, () => Array(N).fill(0));
    for (let i = 0; i < N; i++) for (let j = 0; j < N; j++) r[i][j] = m[N - 1 - j][i];
    return r;
}
function rotateCCW(m) {
    const N = m.length;
    const r = Array.from({ length: N }, () => Array(N).fill(0));
    for (let i = 0; i < N; i++) for (let j = 0; j < N; j++) r[i][j] = m[j][N - 1 - i];
    return r;
}
function cloneMatrix(m) { return m.map((row) => row.slice()); }

let activeScene = null; // 가상 키패드 핸들러가 참조

class TetrisScene extends Phaser.Scene {
    constructor() { super({ key: 'TetrisScene' }); }

    init() {
        this.board = Array.from({ length: ROWS }, () => Array(COLS).fill(0));
        this.bag = [];
        this.nextType = this.drawFromBag();
        this.holdType = null;
        this.canHold = true;
        this.piece = null;
        this.score = 0;
        this.lines = 0;
        this.level = 1;
        this.dropInterval = 800;
        this.elapsedMs = 0;      // 누적 플레이 시간(시간 경과 가속용)
        this.gravityAcc = 0;
        this.lockTimer = 0;
        this.resting = false;
        this.lastActionRotation = false;
        this.softHeld = false;
        this.gameIsOver = false;
        this.dasDir = 0;
        this.dasTimer = 0;
        this.dasCharged = false;
        this.touch = { left: false, right: false, soft: false }; // 가상 키패드 입력 상태
        this.cell = 0; // 레이아웃 완료 전 가드
    }

    create() {
        activeScene = this;
        this.isTouch = isTouchDevice();
        this.gfx = this.add.graphics();

        const base = { fontFamily: 'Outfit, sans-serif', color: '#f8fafc', fontStyle: 'bold' };
        const muted = { fontFamily: 'Outfit, sans-serif', color: '#94a3b8', fontStyle: 'bold' };
        this.holdLabel = this.add.text(0, 0, 'HOLD', muted);
        this.nextLabel = this.add.text(0, 0, 'NEXT', muted);
        this.statText = this.add.text(0, 0, '', base).setOrigin(0.5);
        this.scoreLabel = this.add.text(0, 0, '점수', muted);
        this.scoreText = this.add.text(0, 0, '0', base);
        this.levelLabel = this.add.text(0, 0, '레벨', muted);
        this.levelText = this.add.text(0, 0, '1', base);
        this.linesLabel = this.add.text(0, 0, '라인', muted);
        this.linesText = this.add.text(0, 0, '0', base);
        this.flashText = this.add.text(0, 0, '', { fontFamily: 'Outfit, sans-serif', color: '#2dd4bf', fontStyle: 'bold' })
            .setOrigin(0.5).setAlpha(0).setDepth(10);

        this.keys = this.input.keyboard.addKeys({
            left: 'LEFT', right: 'RIGHT', down: 'DOWN',
            cw: 'UP', cw2: 'X', ccw: 'Z',
            hard: 'SPACE', hold: 'C', holdShift: 'SHIFT', restart: 'R',
        });
        this.input.keyboard.addCapture('LEFT,RIGHT,UP,DOWN,SPACE,Z,X,C,SHIFT,R');

        this.setupCanvasGestures();

        // 화면 크기/회전 변경 시 레이아웃 재계산
        this.scale.on('resize', this.layout, this);
        this.layout();
        // 컨테이너 크기가 한 박자 늦게 확정되는 경우 대비
        this.time.delayedCall(60, () => this.layout());

        this.spawnPiece();
        this.refreshTexts();
    }

    shutdown() { this.scale.off('resize', this.layout, this); }

    // ---------------------------------------------------------------- 반응형 레이아웃
    layout() {
        const W = this.scale.gameSize.width;
        const H = this.scale.gameSize.height;
        if (!W || !H) return;
        this.viewW = W; this.viewH = H;

        const pad = Math.round(Math.min(W, H) * 0.02) + 6;
        this.pad = pad;

        // 가상 키패드는 flex 흐름에서 캔버스(#game-container) 아래에 위치하므로
        // 캔버스 높이(H)에 키패드 영역이 이미 빠져 있다 → 추가로 뺄 필요 없음.
        const usableH = H;
        const portrait = H >= W * 1.05;
        this.portrait = portrait;

        if (portrait) {
            const lblH = 16;
            const topH = clamp(usableH * 0.12, 62, 104);
            const availH = usableH - topH - pad * 2;
            const availW = W - pad * 2;
            const cell = Math.max(10, Math.floor(Math.min(availW / COLS, availH / ROWS)));
            this.cell = cell;
            this.boardW = cell * COLS;
            this.boardH = cell * ROWS;
            this.boardX = Math.floor((W - this.boardW) / 2);
            this.boardY = Math.floor(topH + pad + (availH - this.boardH) / 2);

            const previewSize = Math.min(topH - lblH - 8, cell * 3.2);
            this.holdBox = { x: pad, y: lblH + 4, size: previewSize };
            this.nextBox = { x: pad + previewSize + 14, y: lblH + 4, size: previewSize };
        } else {
            const availH = usableH - pad * 2;
            const cell = Math.max(10, Math.floor(Math.min(availH / ROWS, (W * 0.62) / COLS)));
            this.cell = cell;
            this.boardW = cell * COLS;
            this.boardH = cell * ROWS;

            const panelW = clamp(cell * 4 + 24, 110, 240);
            const gap = Math.round(cell * 0.7) + 10;
            const groupW = this.boardW + gap + panelW;
            const startX = Math.floor((W - groupW) / 2);
            this.boardX = Math.max(pad, startX);
            this.boardY = Math.floor((usableH - this.boardH) / 2 + pad);
            this.panelX = this.boardX + this.boardW + gap;
            this.panelW = panelW;

            const previewSize = Math.min(panelW, cell * 4);
            this.holdBox = { x: this.panelX, y: this.boardY + 22, size: previewSize };
            this.nextBox = { x: this.panelX, y: this.holdBox.y + previewSize + 46, size: previewSize };
            this.scoreY = this.nextBox.y + previewSize + 28;
        }

        this.repositionTexts();
    }

    repositionTexts() {
        const cell = this.cell;
        const lblSize = Math.round(clamp(cell * 0.5, 11, 16));
        const valSize = Math.round(clamp(cell * 0.85, 16, 26));
        const statSize = Math.round(clamp(cell * 0.55, 12, 19));
        const flashSize = Math.round(clamp(cell * 1.1, 22, 42));

        [this.holdLabel, this.nextLabel, this.scoreLabel, this.levelLabel, this.linesLabel].forEach((t) => t.setFontSize(lblSize));
        [this.scoreText, this.levelText, this.linesText].forEach((t) => t.setFontSize(valSize));
        this.statText.setFontSize(statSize);
        this.flashText.setFontSize(flashSize).setPosition(this.boardX + this.boardW / 2, this.boardY + this.boardH / 2);

        if (this.portrait) {
            this.holdLabel.setVisible(true).setPosition(this.holdBox.x, Math.max(2, this.holdBox.y - lblSize - 2));
            this.nextLabel.setVisible(true).setPosition(this.nextBox.x, Math.max(2, this.nextBox.y - lblSize - 2));
            const cx = (this.nextBox.x + this.nextBox.size + (this.viewW - this.pad)) / 2;
            this.statText.setVisible(true).setPosition(cx, this.holdBox.y + this.holdBox.size / 2);
            [this.scoreLabel, this.scoreText, this.levelLabel, this.levelText, this.linesLabel, this.linesText]
                .forEach((t) => t.setVisible(false));
        } else {
            this.statText.setVisible(false);
            const px = this.panelX;
            this.holdLabel.setVisible(true).setPosition(px, this.holdBox.y - lblSize - 4);
            this.nextLabel.setVisible(true).setPosition(px, this.nextBox.y - lblSize - 4);
            let y = this.scoreY;
            const step = lblSize + valSize + 16;
            this.scoreLabel.setVisible(true).setPosition(px, y); this.scoreText.setVisible(true).setPosition(px, y + lblSize + 2); y += step;
            this.levelLabel.setVisible(true).setPosition(px, y); this.levelText.setVisible(true).setPosition(px, y + lblSize + 2); y += step;
            this.linesLabel.setVisible(true).setPosition(px, y); this.linesText.setVisible(true).setPosition(px, y + lblSize + 2);
        }
    }

    // ---------------------------------------------------------------- 랜덤(7-bag)
    drawFromBag() {
        if (this.bag.length === 0) {
            const b = ['I', 'O', 'T', 'S', 'Z', 'J', 'L'];
            for (let i = b.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [b[i], b[j]] = [b[j], b[i]];
            }
            this.bag = b;
        }
        return this.bag.pop();
    }

    spawnPiece(forcedType = null) {
        const type = forcedType || this.nextType;
        if (!forcedType) this.nextType = this.drawFromBag();

        const def = SHAPES[type];
        const matrix = cloneMatrix(def.cells);
        const N = matrix.length;
        let minRow = N;
        for (let r = 0; r < N; r++) for (let c = 0; c < N; c++) if (matrix[r][c]) minRow = Math.min(minRow, r);
        this.piece = { type, color: def.color, matrix, x: Math.floor((COLS - N) / 2), y: -minRow };
        this.lastActionRotation = false;
        this.lockTimer = 0;
        this.gravityAcc = 0;

        if (this.collide(this.piece.matrix, this.piece.x, this.piece.y)) this.doGameOver();
    }

    collide(matrix, px, py) {
        const N = matrix.length;
        for (let r = 0; r < N; r++) {
            for (let c = 0; c < N; c++) {
                if (!matrix[r][c]) continue;
                const bx = px + c, by = py + r;
                if (bx < 0 || bx >= COLS || by >= ROWS) return true;
                if (by >= 0 && this.board[by][bx]) return true;
            }
        }
        return false;
    }

    move(dx, dy) {
        if (this.collide(this.piece.matrix, this.piece.x + dx, this.piece.y + dy)) return false;
        this.piece.x += dx; this.piece.y += dy;
        this.lastActionRotation = false;
        if (this.resting) this.lockTimer = 0;
        return true;
    }

    rotate(dir) {
        if (this.gameIsOver || !this.piece) return false;
        const rotated = dir > 0 ? rotateCW(this.piece.matrix) : rotateCCW(this.piece.matrix);
        const kicks = [[0, 0], [-1, 0], [1, 0], [-2, 0], [2, 0], [0, -1], [-1, -1], [1, -1]];
        for (const [ox, oy] of kicks) {
            if (!this.collide(rotated, this.piece.x + ox, this.piece.y + oy)) {
                this.piece.matrix = rotated;
                this.piece.x += ox; this.piece.y += oy;
                this.lastActionRotation = true;
                if (this.resting) this.lockTimer = 0;
                return true;
            }
        }
        return false;
    }

    holdSwap() {
        if (this.gameIsOver || !this.canHold) return;
        const cur = this.piece.type;
        if (this.holdType === null) { this.holdType = cur; this.spawnPiece(); }
        else { const swap = this.holdType; this.holdType = cur; this.spawnPiece(swap); }
        this.canHold = false;
    }

    hardDrop() {
        if (this.gameIsOver || !this.piece) return;
        let dist = 0;
        while (!this.collide(this.piece.matrix, this.piece.x, this.piece.y + 1)) { this.piece.y += 1; dist++; }
        this.score += dist * 2;
        this.lockPiece();
    }

    lockPiece() {
        const m = this.piece.matrix, N = m.length;
        for (let r = 0; r < N; r++) {
            for (let c = 0; c < N; c++) {
                if (!m[r][c]) continue;
                const bx = this.piece.x + c, by = this.piece.y + r;
                if (by < 0) { this.doGameOver(); return; }
                this.board[by][bx] = this.piece.color;
            }
        }

        const tSpin = this.piece.type === 'T' && this.lastActionRotation && this.countTCorners() >= 3;
        const cleared = this.clearLines();

        let base = LINE_SCORE[cleared] || 0;
        if (tSpin && cleared === 0) base = 400;
        let pts = base * this.level;
        if (tSpin) pts *= 2;
        this.score += pts;

        if (cleared > 0) this.lines += cleared;
        this.level = Math.floor(this.lines / 10) + 1;
        // dropInterval 은 update()에서 레벨 + 경과시간으로 매 프레임 재계산한다.

        if (tSpin) this.showFlash('T-SPIN! x2', '#a855f7');
        else if (cleared === 4) this.showFlash('TETRIS!', '#22d3ee');
        else if (cleared > 0) this.showFlash(cleared + ' LINE', '#2dd4bf');

        this.refreshTexts();
        this.canHold = true;
        this.spawnPiece();
    }

    countTCorners() {
        const cx = this.piece.x + 1, cy = this.piece.y + 1;
        const corners = [[cx - 1, cy - 1], [cx + 1, cy - 1], [cx - 1, cy + 1], [cx + 1, cy + 1]];
        let n = 0;
        for (const [x, y] of corners) {
            if (x < 0 || x >= COLS || y >= ROWS) { n++; continue; }
            if (y >= 0 && this.board[y][x]) n++;
        }
        return n;
    }

    clearLines() {
        let cleared = 0;
        for (let r = ROWS - 1; r >= 0; r--) {
            if (this.board[r].every((v) => v !== 0)) {
                this.board.splice(r, 1);
                this.board.unshift(Array(COLS).fill(0));
                cleared++; r++;
            }
        }
        return cleared;
    }

    ghostY() {
        let gy = this.piece.y;
        while (!this.collide(this.piece.matrix, this.piece.x, gy + 1)) gy++;
        return gy;
    }

    // ---------------------------------------------------------------- 루프
    update(time, delta) {
        if (this.gameIsOver) {
            if (Phaser.Input.Keyboard.JustDown(this.keys.restart)) this.scene.restart();
            return;
        }
        if (!this.cell || !this.piece) return;

        this.handleInput(delta);

        // 낙하 속도: 레벨(줄 삭제) + 시간 경과 둘 다로 가속. 12초마다 -35ms(최대 -520ms), 하한 60ms.
        this.elapsedMs += delta;
        const timeSpeedup = Math.min(Math.floor(this.elapsedMs / 12000) * 35, 520);
        this.dropInterval = Math.max(60, 800 - (this.level - 1) * 70 - timeSpeedup);

        this.softHeld = this.keys.down.isDown || this.touch.soft;
        const interval = this.softHeld ? SOFT_INTERVAL : this.dropInterval;
        this.gravityAcc += delta;
        if (this.gravityAcc >= interval) {
            this.gravityAcc = 0;
            if (!this.collide(this.piece.matrix, this.piece.x, this.piece.y + 1)) {
                this.piece.y += 1;
                this.lastActionRotation = false;
                if (this.softHeld) this.score += 1;
            }
        }

        this.resting = this.collide(this.piece.matrix, this.piece.x, this.piece.y + 1);
        if (this.resting) {
            this.lockTimer += delta;
            if (this.lockTimer >= LOCK_DELAY) this.lockPiece();
        } else {
            this.lockTimer = 0;
        }

        this.refreshTexts();
        this.draw();
    }

    handleInput(delta) {
        const k = this.keys;
        if (Phaser.Input.Keyboard.JustDown(k.cw) || Phaser.Input.Keyboard.JustDown(k.cw2)) this.rotate(1);
        if (Phaser.Input.Keyboard.JustDown(k.ccw)) this.rotate(-1);
        if (Phaser.Input.Keyboard.JustDown(k.hard)) { this.hardDrop(); return; }
        if (Phaser.Input.Keyboard.JustDown(k.hold) || Phaser.Input.Keyboard.JustDown(k.holdShift)) this.holdSwap();

        // 좌우 이동 + DAS/ARR (키보드 + 가상 키패드 공통)
        const leftDown = k.left.isDown || this.touch.left;
        const rightDown = k.right.isDown || this.touch.right;
        let dir = 0;
        if (leftDown && !rightDown) dir = -1;
        else if (rightDown && !leftDown) dir = 1;

        if (dir !== 0 && dir !== this.dasDir) {
            this.move(dir, 0);
            this.dasDir = dir; this.dasTimer = 0; this.dasCharged = false;
        } else if (dir !== 0) {
            this.dasTimer += delta;
            if (!this.dasCharged && this.dasTimer >= DAS) { this.dasCharged = true; this.dasTimer = 0; this.move(dir, 0); }
            else if (this.dasCharged && this.dasTimer >= ARR) { this.dasTimer = 0; this.move(dir, 0); }
        } else {
            this.dasDir = 0;
        }
    }

    // ---------------------------------------------------------------- 보드 위 제스처(보너스)
    setupCanvasGestures() {
        let sx = 0, sy = 0, st = 0, moved = false, lastStepX = 0;
        this.input.on('pointerdown', (p) => {
            if (this.gameIsOver) { this.scene.restart(); return; }
            sx = p.x; sy = p.y; st = this.time.now; moved = false; lastStepX = p.x;
        });
        this.input.on('pointermove', (p) => {
            if (!p.isDown || !this.cell) return;
            const step = this.cell;
            while (p.x - lastStepX > step) { this.move(1, 0); lastStepX += step; moved = true; }
            while (lastStepX - p.x > step) { this.move(-1, 0); lastStepX -= step; moved = true; }
        });
        this.input.on('pointerup', (p) => {
            const dx = p.x - sx, dy = p.y - sy, dt = this.time.now - st;
            if (!moved && Math.abs(dx) < 12 && Math.abs(dy) < 12 && dt < 250) this.rotate(1);
            else if (dy < -50 && Math.abs(dy) > Math.abs(dx)) this.holdSwap();
            else if (dy > 50 && Math.abs(dy) > Math.abs(dx)) this.hardDrop();
        });
    }

    // ---------------------------------------------------------------- 렌더
    refreshTexts() {
        this.scoreText.setText(String(this.score));
        this.levelText.setText(String(this.level));
        this.linesText.setText(String(this.lines));
        this.statText.setText(`점수 ${this.score}  ·  Lv ${this.level}  ·  ${this.lines}줄`);
    }

    showFlash(msg, color) {
        this.flashText.setText(msg).setColor(color).setAlpha(1).setScale(1);
        this.tweens.add({ targets: this.flashText, alpha: 0, scale: 1.4, duration: 800, ease: 'Cubic.easeOut' });
    }

    drawCell(px, py, size, color, alpha) {
        this.gfx.fillStyle(color, alpha);
        this.gfx.fillRect(px + 1, py + 1, size - 2, size - 2);
        this.gfx.fillStyle(0xffffff, alpha * 0.18);
        this.gfx.fillRect(px + 1, py + 1, size - 2, Math.max(2, size * 0.18));
    }

    drawMatrixAt(matrix, originX, originY, size, color, alpha) {
        const N = matrix.length;
        for (let r = 0; r < N; r++) for (let c = 0; c < N; c++) {
            if (matrix[r][c]) this.drawCell(originX + c * size, originY + r * size, size, color, alpha);
        }
    }

    drawPreview(type, box) {
        if (!type) return;
        const def = SHAPES[type], m = def.cells, N = m.length;
        const cs = Math.floor(box.size / 4);
        let minC = N, maxC = -1, minR = N, maxR = -1;
        for (let r = 0; r < N; r++) for (let c = 0; c < N; c++) if (m[r][c]) {
            minC = Math.min(minC, c); maxC = Math.max(maxC, c);
            minR = Math.min(minR, r); maxR = Math.max(maxR, r);
        }
        const w = (maxC - minC + 1) * cs, h = (maxR - minR + 1) * cs;
        const ox = box.x + (box.size - w) / 2 - minC * cs;
        const oy = box.y + (box.size - h) / 2 - minR * cs;
        for (let r = 0; r < N; r++) for (let c = 0; c < N; c++) {
            if (m[r][c]) this.drawCell(ox + c * cs, oy + r * cs, cs, def.color, 1);
        }
    }

    draw() {
        const g = this.gfx, cell = this.cell;
        if (!cell) return;
        g.clear();

        g.fillStyle(0x0f172a, 1);
        g.fillRect(this.boardX, this.boardY, this.boardW, this.boardH);
        g.lineStyle(1, 0x1e293b, 1);
        for (let c = 0; c <= COLS; c++) g.lineBetween(this.boardX + c * cell, this.boardY, this.boardX + c * cell, this.boardY + this.boardH);
        for (let r = 0; r <= ROWS; r++) g.lineBetween(this.boardX, this.boardY + r * cell, this.boardX + this.boardW, this.boardY + r * cell);

        for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) {
            if (this.board[r][c]) this.drawCell(this.boardX + c * cell, this.boardY + r * cell, cell, this.board[r][c], 1);
        }

        if (this.piece) {
            const gy = this.ghostY();
            this.drawMatrixAt(this.piece.matrix, this.boardX + this.piece.x * cell, this.boardY + gy * cell, cell, this.piece.color, 0.22);
            const m = this.piece.matrix, N = m.length;
            for (let r = 0; r < N; r++) for (let c = 0; c < N; c++) {
                if (!m[r][c]) continue;
                const by = this.piece.y + r;
                if (by < 0) continue;
                this.drawCell(this.boardX + (this.piece.x + c) * cell, this.boardY + by * cell, cell, this.piece.color, 1);
            }
        }

        g.lineStyle(1, 0x334155, 1);
        g.strokeRect(this.holdBox.x, this.holdBox.y, this.holdBox.size, this.holdBox.size);
        g.strokeRect(this.nextBox.x, this.nextBox.y, this.nextBox.size, this.nextBox.size);
        this.drawPreview(this.holdType, this.holdBox);
        this.drawPreview(this.nextType, this.nextBox);
    }

    doGameOver() {
        if (this.gameIsOver) return;
        this.gameIsOver = true;
        const cx = this.boardX + this.boardW / 2;
        const cy = this.boardY + this.boardH / 2;
        this.add.rectangle(cx, cy, this.boardW, this.boardH, 0x000000, 0.72).setDepth(20);
        this.add.text(cx, cy - 50, 'GAME OVER', { fontFamily: 'Outfit, sans-serif', fontSize: '34px', color: '#f87171', fontStyle: 'bold' }).setOrigin(0.5).setDepth(21);
        this.add.text(cx, cy, '점수 ' + this.score, { fontFamily: 'Outfit, sans-serif', fontSize: '24px', color: '#f8fafc', fontStyle: 'bold' }).setOrigin(0.5).setDepth(21);
        this.add.text(cx, cy + 50, 'R 키 / 화면 탭으로 다시 시작', { fontFamily: 'Outfit, sans-serif', fontSize: '15px', color: '#94a3b8' }).setOrigin(0.5).setDepth(21);

        // 랭킹 오버레이 표시 (닉네임 입력 → 점수 등록 → 랭킹/재시작)
        window.MiniGameRanking?.show(this.score);
    }
}

let tetrisGame = null;
function getPhaserConfig() {
    return {
        type: Phaser.AUTO,
        parent: 'game-container',
        backgroundColor: '#0b1120',
        scale: {
            mode: Phaser.Scale.RESIZE,         // 컨테이너(=화면)를 꽉 채움
            width: window.innerWidth,
            height: window.innerHeight,
        },
        scene: [TetrisScene],
    };
}

// 가상 키패드 바인딩 (터치 기기에서만 표시)
function bindTouchControls() {
    const tc = document.getElementById('touchControls');
    if (!tc || !isTouchDevice()) return;
    tc.style.display = 'flex';
    tc.setAttribute('aria-hidden', 'false');

    tc.querySelectorAll('.tc-btn').forEach((btn) => {
        const act = btn.dataset.act;
        const start = (e) => {
            e.preventDefault();
            if (!activeScene) return;
            if (activeScene.gameIsOver) { activeScene.scene.restart(); return; }
            if (act === 'left') activeScene.touch.left = true;
            else if (act === 'right') activeScene.touch.right = true;
            else if (act === 'softdrop') activeScene.touch.soft = true;
            else if (act === 'rotate') activeScene.rotate(1);
            else if (act === 'hold') activeScene.holdSwap();
            else if (act === 'harddrop') activeScene.hardDrop();
        };
        const end = (e) => {
            e.preventDefault();
            if (!activeScene) return;
            if (act === 'left') activeScene.touch.left = false;
            else if (act === 'right') activeScene.touch.right = false;
            else if (act === 'softdrop') activeScene.touch.soft = false;
        };
        btn.addEventListener('touchstart', start, { passive: false });
        btn.addEventListener('touchend', end, { passive: false });
        btn.addEventListener('touchcancel', end, { passive: false });
        // 데스크톱(마우스)에서도 클릭 테스트 가능하도록
        btn.addEventListener('mousedown', start);
        btn.addEventListener('mouseup', end);
        btn.addEventListener('mouseleave', end);
    });
}

// 게임 시작 버튼
const startBtn = document.getElementById('startGameBtn');
if (startBtn) {
    startBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        document.getElementById('startScreen').style.display = 'none';
        const gc = document.getElementById('game-container');
        gc.style.display = 'flex';
        gc.addEventListener('contextmenu', (ev) => ev.preventDefault(), false);
        bindTouchControls();                 // 먼저 키패드 표시 → 레이아웃이 높이를 반영
        if (!tetrisGame) tetrisGame = new Phaser.Game(getPhaserConfig());
        this.disabled = true;
        this.textContent = '게임 진행 중...';
    });
}
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
