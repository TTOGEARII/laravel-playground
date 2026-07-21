import { COLS, ROWS } from './engine.js';

// 색 인덱스(엔진 color) → 색. 0=빈칸, 1~7=조각, 8=가비지.
const COLORS = ['#151515', '#2ee6e6', '#f2d94e', '#c061f0', '#4ade80', '#f26a6a', '#5b8bf0', '#f2913d', '#7a7a7a'];

function cell(ctx, x, y, size, color, alpha = 1) {
    ctx.globalAlpha = alpha;
    ctx.fillStyle = color;
    ctx.fillRect(x * size, y * size, size - 1, size - 1);
    ctx.globalAlpha = 1;
}

/** 내 보드(격자 + 고스트 + 활성 조각). */
export function drawBoard(ctx, engine, size) {
    ctx.fillStyle = '#0d0d0d';
    ctx.fillRect(0, 0, COLS * size, ROWS * size);

    for (let y = 0; y < ROWS; y++) {
        for (let x = 0; x < COLS; x++) {
            if (engine.grid[y][x]) cell(ctx, x, y, size, COLORS[engine.grid[y][x]]);
        }
    }

    if (engine.active && !engine.gameOver) {
        const c = COLORS[cellColor(engine)];
        // 고스트
        const gy = engine.ghostY();
        for (const [px, py] of engine.cells({ ...engine.active, y: gy })) {
            if (py >= 0) cell(ctx, px, py, size, c, 0.22);
        }
        // 활성 조각
        for (const [px, py] of engine.cells(engine.active)) {
            if (py >= 0) cell(ctx, px, py, size, c);
        }
    }
}

function cellColor(engine) {
    // 조각 타입 색은 engine 이 PIECES 로 알지만 외부 노출이 없어, 그리드에 굳기 전엔 타입에서 유추.
    const colorByType = { I: 1, O: 2, T: 3, S: 4, Z: 5, J: 6, L: 7 };
    return colorByType[engine.active.type] ?? 3;
}

/** 상대 보드(스냅샷). snap = engine.serialize() 결과. */
export function drawSnapshot(ctx, snap, size) {
    ctx.fillStyle = '#0d0d0d';
    ctx.fillRect(0, 0, COLS * size, ROWS * size);
    if (!snap) return;
    const rows = String(snap.g).split('|');
    for (let y = 0; y < rows.length && y < ROWS; y++) {
        for (let x = 0; x < COLS; x++) {
            if (rows[y][x] === '1') cell(ctx, x, y, size, '#8a8a8a');
        }
    }
    for (const [x, y] of snap.p || []) {
        if (y >= 0) cell(ctx, x, y, size, '#f3727f');
    }
    if (snap.o) {
        ctx.globalAlpha = 0.55;
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, COLS * size, ROWS * size);
        ctx.globalAlpha = 1;
    }
}

/** 다음 조각 미리보기. */
export function drawNext(ctx, type, size) {
    ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    if (!type) return;
    const colorByType = { I: 1, O: 2, T: 3, S: 4, Z: 5, J: 6, L: 7 };
    // engine 의 PIECES 회전0 셀을 다시 정의하기보다, 간단히 타입별 셀 하드코딩.
    const spawn = {
        I: [[0, 1], [1, 1], [2, 1], [3, 1]], O: [[1, 0], [2, 0], [1, 1], [2, 1]],
        T: [[1, 0], [0, 1], [1, 1], [2, 1]], S: [[1, 0], [2, 0], [0, 1], [1, 1]],
        Z: [[0, 0], [1, 0], [1, 1], [2, 1]], J: [[0, 0], [0, 1], [1, 1], [2, 1]],
        L: [[2, 0], [0, 1], [1, 1], [2, 1]],
    }[type];
    for (const [x, y] of spawn) cell(ctx, x, y, size, COLORS[colorByType[type]]);
}
