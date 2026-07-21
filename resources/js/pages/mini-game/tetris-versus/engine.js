/**
 * 대전용 경량 테트리스 엔진(헤드리스 — 렌더/입력과 분리).
 * 네트코드를 얹기 쉽게: 줄 삭제 시 onLineClear(공격), 탑아웃 시 onTopOut,
 * serialize()로 상대에게 보낼 보드 스냅샷을 만든다. 각 플레이어는 자기 7-bag 을 쓴다.
 */

export const COLS = 10;
export const ROWS = 20;
const SPAWN_ROW = -1; // 살짝 위에서 스폰(버퍼)

// 각 조각의 4 회전 상태 = 4x4 박스 안 셀 좌표 [col,row]. 색 인덱스는 렌더가 사용.
const PIECES = {
    I: { color: 1, rot: [[[0, 1], [1, 1], [2, 1], [3, 1]], [[2, 0], [2, 1], [2, 2], [2, 3]], [[0, 2], [1, 2], [2, 2], [3, 2]], [[1, 0], [1, 1], [1, 2], [1, 3]]] },
    O: { color: 2, rot: [[[1, 0], [2, 0], [1, 1], [2, 1]], [[1, 0], [2, 0], [1, 1], [2, 1]], [[1, 0], [2, 0], [1, 1], [2, 1]], [[1, 0], [2, 0], [1, 1], [2, 1]]] },
    T: { color: 3, rot: [[[1, 0], [0, 1], [1, 1], [2, 1]], [[1, 0], [1, 1], [2, 1], [1, 2]], [[0, 1], [1, 1], [2, 1], [1, 2]], [[1, 0], [0, 1], [1, 1], [1, 2]]] },
    S: { color: 4, rot: [[[1, 0], [2, 0], [0, 1], [1, 1]], [[1, 0], [1, 1], [2, 1], [2, 2]], [[1, 1], [2, 1], [0, 2], [1, 2]], [[0, 0], [0, 1], [1, 1], [1, 2]]] },
    Z: { color: 5, rot: [[[0, 0], [1, 0], [1, 1], [2, 1]], [[2, 0], [1, 1], [2, 1], [1, 2]], [[0, 1], [1, 1], [1, 2], [2, 2]], [[1, 0], [0, 1], [1, 1], [0, 2]]] },
    J: { color: 6, rot: [[[0, 0], [0, 1], [1, 1], [2, 1]], [[1, 0], [2, 0], [1, 1], [1, 2]], [[0, 1], [1, 1], [2, 1], [2, 2]], [[1, 0], [1, 1], [0, 2], [1, 2]]] },
    L: { color: 7, rot: [[[2, 0], [0, 1], [1, 1], [2, 1]], [[1, 0], [1, 1], [1, 2], [2, 2]], [[0, 1], [1, 1], [2, 1], [0, 2]], [[0, 0], [1, 0], [1, 1], [1, 2]]] },
};
const TYPES = Object.keys(PIECES);

// 기본 회전 보정(간단 킥) — 회전 후 겹치면 좌/우/위로 살짝 밀어본다(SRS 전체는 아니지만 체감 충분).
const KICKS = [[0, 0], [-1, 0], [1, 0], [0, -1], [-1, -1], [1, -1], [-2, 0], [2, 0]];

// 삭제 줄 수 → 상대에게 보내는 가비지 줄 수(단순화, b2b/콤보 없음).
const GARBAGE_TABLE = [0, 0, 1, 2, 4];

export class TetrisEngine {
    constructor({ onLineClear, onTopOut, onChange } = {}) {
        this.onLineClear = onLineClear || (() => {});
        this.onTopOut = onTopOut || (() => {});
        this.onChange = onChange || (() => {}); // 상태 변화(락/이동) 알림 → 스냅샷 전송용
        this.reset();
    }

    reset() {
        this.grid = Array.from({ length: ROWS }, () => Array(COLS).fill(0));
        this.bag = [];
        this.queue = [];
        this.hold = null;
        this.holdUsed = false;
        this.garbageQueue = 0; // 받은(대기) 가비지 줄
        this.score = 0;
        this.lines = 0;
        this.gameOver = false;
        for (let i = 0; i < 5; i++) this.queue.push(this.pull());
        this.spawn();
    }

    // 7-bag
    pull() {
        if (this.bag.length === 0) {
            this.bag = [...TYPES];
            for (let i = this.bag.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [this.bag[i], this.bag[j]] = [this.bag[j], this.bag[i]];
            }
        }
        return this.bag.pop();
    }

    spawn(type = null) {
        const t = type || this.queue.shift();
        if (!type) this.queue.push(this.pull());
        this.active = { type: t, rot: 0, x: 3, y: SPAWN_ROW };
        this.holdUsed = false;
        if (this.collides(this.active)) {
            this.gameOver = true;
            this.onTopOut(this);
        }
        this.onChange(this);
    }

    cells(p) {
        return PIECES[p.type].rot[p.rot].map(([cx, cy]) => [p.x + cx, p.y + cy]);
    }

    collides(p) {
        return this.cells(p).some(([x, y]) => x < 0 || x >= COLS || y >= ROWS || (y >= 0 && this.grid[y][x]));
    }

    move(dx, dy) {
        if (this.gameOver) return false;
        const np = { ...this.active, x: this.active.x + dx, y: this.active.y + dy };
        if (!this.collides(np)) { this.active = np; this.onChange(this); return true; }
        return false;
    }

    rotate(dir) {
        if (this.gameOver) return;
        const nr = (this.active.rot + (dir > 0 ? 1 : 3)) % 4;
        for (const [kx, ky] of KICKS) {
            const np = { ...this.active, rot: nr, x: this.active.x + kx, y: this.active.y + ky };
            if (!this.collides(np)) { this.active = np; this.onChange(this); return; }
        }
    }

    softDrop() { if (!this.move(0, 1)) this.lock(); }

    hardDrop() {
        if (this.gameOver) return;
        while (this.move(0, 1)) { /* 바닥까지 */ }
        this.lock();
    }

    holdPiece() {
        if (this.gameOver || this.holdUsed) return;
        const cur = this.active.type;
        if (this.hold) {
            const h = this.hold;
            this.hold = cur;
            this.spawn(h);
        } else {
            this.hold = cur;
            this.spawn();
        }
        this.holdUsed = true;
    }

    lock() {
        for (const [x, y] of this.cells(this.active)) {
            if (y < 0) { this.gameOver = true; this.onTopOut(this); return; } // 버퍼 위에서 굳음 = 탑아웃
            this.grid[y][x] = PIECES[this.active.type].color;
        }
        const cleared = this.clearLines();
        if (cleared > 0) {
            this.lines += cleared;
            this.score += [0, 100, 300, 500, 800][cleared] || 0;
            this.onLineClear(GARBAGE_TABLE[cleared] || 0, this);
        } else {
            this.applyGarbage(); // 줄을 못 지웠을 때만 받은 가비지를 올린다
        }
        this.spawn();
    }

    clearLines() {
        let cleared = 0;
        for (let y = ROWS - 1; y >= 0; y--) {
            if (this.grid[y].every((c) => c !== 0)) {
                this.grid.splice(y, 1);
                this.grid.unshift(Array(COLS).fill(0));
                cleared++;
                y++;
            }
        }
        return cleared;
    }

    receiveGarbage(n) { this.garbageQueue += n; this.onChange(this); }

    applyGarbage() {
        if (this.garbageQueue <= 0) return;
        const n = this.garbageQueue;
        this.garbageQueue = 0;
        const hole = Math.floor(Math.random() * COLS);
        for (let i = 0; i < n; i++) {
            this.grid.shift(); // 맨 위 제거(밀려 올라감)
            const row = Array(COLS).fill(8); // 8 = 가비지 색
            row[hole] = 0;
            this.grid.push(row);
        }
        // 밀려 올라가며 활성 조각이 겹치면 위로 보정
        while (this.collides(this.active) && this.active.y > SPAWN_ROW) this.active.y--;
        if (this.collides(this.active)) { this.gameOver = true; this.onTopOut(this); }
        this.onChange(this);
    }

    // 바닥 착지 지점(고스트) y
    ghostY() {
        const g = { ...this.active };
        while (!this.collides({ ...g, y: g.y + 1 })) g.y++;
        return g.y;
    }

    /** 상대에게 보낼 압축 스냅샷: 보드(굳은 칸)+현재 조각 셀. */
    serialize() {
        const rows = this.grid.map((r) => r.map((c) => (c ? 1 : 0)).join('')).join('|');
        const piece = this.cells(this.active).filter(([, y]) => y >= 0);
        return { g: rows, p: piece, o: this.gameOver, ln: this.lines };
    }
}
