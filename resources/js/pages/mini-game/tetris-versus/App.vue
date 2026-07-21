<template>
  <div class="tv-root">
    <header class="tv-head">
      <a :href="homeUrl" class="tv-back">← 미니게임</a>
      <h1>테트리스 대전 <small>실시간</small></h1>
    </header>

    <!-- 로비 -->
    <section v-if="view === 'lobby'" class="tv-lobby">
      <p class="tv-lead">아무나와 빠르게 붙거나, 친구와 방을 만들어 대전하세요.</p>

      <!-- 빠른 대전(자동 매칭) -->
      <div v-if="matching" class="tv-matching">
        <span class="tv-spin"></span>
        <span>상대를 찾는 중… <b>{{ matchDots }}</b></span>
        <button class="tv-btn tv-btn-sm" @click="cancelMatchmaking">취소</button>
      </div>
      <div class="tv-lobby-actions">
        <button class="tv-btn tv-btn-primary tv-quick" :disabled="busy || matching" @click="startMatchmaking">⚡ 빠른 대전</button>
        <div class="tv-or">또는</div>
        <button class="tv-btn" :disabled="busy || matching" @click="createRoom">방 만들기</button>
        <div class="tv-join">
          <input v-model.trim="joinCode" maxlength="6" placeholder="방 코드 6자리" class="tv-input" :disabled="matching" @keyup.enter="joinRoom" />
          <button class="tv-btn" :disabled="busy || matching || joinCode.length < 4" @click="joinRoom">입장</button>
        </div>
      </div>
      <p v-if="error" class="tv-error">{{ error }}</p>
    </section>

    <!-- 방 -->
    <section v-else class="tv-room">
      <div class="tv-room-bar">
        <span class="tv-code">방 <b>{{ roomCode }}</b></span>
        <button class="tv-btn tv-btn-sm" @click="copyLink">링크 복사</button>
        <span v-if="copied" class="tv-copied">복사됨!</span>
        <button class="tv-btn tv-btn-sm tv-leave" @click="leaveRoom">나가기</button>
      </div>

      <!-- 관전자(3번째 이후): 안내만(다인/관전 UI는 다음 단계) -->
      <div v-if="!amPlayer" class="tv-members">
        <div class="tv-members-title">관전 중 — 참가자 {{ playerIds.length }}/2</div>
        <p class="tv-waiting">이 방은 이미 두 명이 대전 중입니다. 관전 화면은 준비 중이에요.</p>
      </div>

      <template v-else>
        <!-- 게임 영역 -->
        <div class="tv-arena">
          <div class="tv-side">
            <div class="tv-board-label">나 <b>{{ me.name }}</b></div>
            <div class="tv-canvas-wrap">
              <canvas ref="myCanvas" :width="COLS * MY_CELL" :height="ROWS * MY_CELL" class="tv-canvas"></canvas>
              <div v-if="gamePhase !== 'playing'" class="tv-overlay">
                <template v-if="gamePhase === 'waiting'">상대 대기 중…</template>
                <template v-else-if="gamePhase === 'ready'">
                  <div class="tv-ovbig">준비</div>
                  <button class="tv-btn tv-btn-primary" :disabled="myReady" @click="ready">
                    {{ myReady ? '상대 기다리는 중…' : '준비 완료' }}
                  </button>
                  <div class="tv-ready-state">상대: {{ oppReady ? '준비됨' : '대기' }}</div>
                </template>
                <template v-else-if="gamePhase === 'countdown'">
                  <div class="tv-ovbig tv-count">{{ countdown }}</div>
                </template>
                <template v-else-if="gamePhase === 'result'">
                  <div class="tv-ovbig" :class="result === 'win' ? 'is-win' : 'is-lose'">{{ result === 'win' ? '승리!' : '패배' }}</div>
                  <button class="tv-btn tv-btn-primary" @click="ready">다시 대전</button>
                </template>
              </div>
            </div>
            <div class="tv-stat">줄 {{ myLines }} <span v-if="garbageIn > 0" class="tv-gwarn">⚠ 가비지 {{ garbageIn }}</span></div>
          </div>

          <div class="tv-mid">
            <div class="tv-mini-label">다음</div>
            <canvas ref="nextCanvas" :width="4 * 16" :height="4 * 16" class="tv-mini"></canvas>
            <div class="tv-controls-hint">
              ← → 이동 · ↓ 소프트 · Space 하드<br />Z/↑ 회전 · Shift 홀드
            </div>
          </div>

          <div class="tv-side">
            <div class="tv-board-label">상대 <b>{{ opponentName || '대기' }}</b></div>
            <div class="tv-canvas-wrap">
              <canvas ref="oppCanvas" :width="COLS * OPP_CELL" :height="ROWS * OPP_CELL" class="tv-canvas is-opp"></canvas>
            </div>
            <div class="tv-stat">줄 {{ oppSnap?.ln ?? 0 }}</div>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { createEcho } from '../../../echo.js';
import { TetrisEngine, COLS, ROWS } from './engine.js';
import { drawBoard, drawSnapshot, drawNext } from './render.js';

const props = defineProps({
  me: { type: Object, required: true },
  homeUrl: { type: String, default: '/mini-game' },
  createRoomUrl: { type: String, required: true },
  matchmakeUrl: { type: String, default: '' },
  cancelMatchmakeUrl: { type: String, default: '' },
  csrf: { type: String, required: true },
});

const MY_CELL = 22;
const OPP_CELL = 13;
const CHANNEL_PREFIX = 'tetris-room.';

const view = ref('lobby');
const busy = ref(false);
const error = ref('');
const joinCode = ref('');
const roomCode = ref('');
const members = ref([]);
const copied = ref(false);
const matching = ref(false);
const matchDots = ref('');
let matchTimer = null;
let dotsTimer = null;

// 게임 상태
const gamePhase = ref('waiting'); // waiting | ready | countdown | playing | result
const myReady = ref(false);
const oppReady = ref(false);
const countdown = ref(3);
const result = ref(null);
const myLines = ref(0);
const garbageIn = ref(0);
const oppSnap = ref(null);

const myCanvas = ref(null);
const oppCanvas = ref(null);
const nextCanvas = ref(null);

let echo = null;
let channel = null;
let engine = null;
let rafId = null;
let lastTs = 0;
let dropAcc = 0;
let snapTimer = null;

// 참가자 = 입장순(id) 앞 2명
const sortedIds = computed(() => members.value.map((m) => m.id).sort((a, b) => a - b));
const playerIds = computed(() => sortedIds.value.slice(0, 2));
const amPlayer = computed(() => playerIds.value.includes(props.me.id));
const isHost = computed(() => playerIds.value.length > 0 && props.me.id === playerIds.value[0]);
const opponentId = computed(() => playerIds.value.find((id) => id !== props.me.id) ?? null);
const opponentName = computed(() => members.value.find((m) => m.id === opponentId.value)?.name ?? '');

// 두 명이 모이면 대기→준비
function refreshPhase() {
  if (gamePhase.value === 'playing' || gamePhase.value === 'countdown') return;
  if (gamePhase.value === 'result') return;
  gamePhase.value = playerIds.value.length >= 2 ? 'ready' : 'waiting';
}

// ── 빠른 대전(자동 매칭, 폴링) ──────────────────────
function mmHeaders() {
  return { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': props.csrf, Accept: 'application/json' };
}
function startMatchmaking() {
  if (!props.matchmakeUrl || matching.value) return;
  matching.value = true; error.value = '';
  let n = 0;
  dotsTimer = setInterval(() => { n = (n + 1) % 4; matchDots.value = '.'.repeat(n); }, 400);
  pollMatch();
  matchTimer = setInterval(pollMatch, 2000);
}
async function pollMatch() {
  try {
    const res = await fetch(props.matchmakeUrl, { method: 'POST', headers: mmHeaders() });
    const { data } = await res.json();
    if (data.status === 'matched') { stopMatchmaking(); enterRoom(data.code); }
  } catch { /* 다음 폴에서 재시도 */ }
}
function cancelMatchmaking() {
  stopMatchmaking();
  if (props.cancelMatchmakeUrl) fetch(props.cancelMatchmakeUrl, { method: 'POST', headers: mmHeaders() }).catch(() => {});
}
function stopMatchmaking() {
  matching.value = false;
  if (matchTimer) { clearInterval(matchTimer); matchTimer = null; }
  if (dotsTimer) { clearInterval(dotsTimer); dotsTimer = null; }
}

// ── 방 입장/네트워킹 ────────────────────────────────
async function createRoom() {
  busy.value = true; error.value = '';
  try {
    const res = await fetch(props.createRoomUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': props.csrf, Accept: 'application/json' },
    });
    if (!res.ok) throw new Error();
    const { data } = await res.json();
    enterRoom(data.code);
  } catch { error.value = '방을 만들지 못했어요. 잠시 후 다시 시도해주세요.'; }
  finally { busy.value = false; }
}

function joinRoom() {
  const code = joinCode.value.toUpperCase();
  if (code.length < 4) return;
  enterRoom(code);
}

function enterRoom(code) {
  stopMatchmaking();
  roomCode.value = code;
  view.value = 'room';
  const url = new URL(window.location.href);
  url.searchParams.set('room', code);
  window.history.replaceState({}, '', url);

  echo = createEcho();
  channel = echo.join(CHANNEL_PREFIX + code)
    .here((users) => { members.value = users; refreshPhase(); })
    .joining((user) => {
      if (!members.value.some((m) => m.id === user.id)) members.value.push(user);
      refreshPhase();
      // 새로 들어온 사람에게 내 준비 상태를 다시 알림(늦게 입장한 피어 동기화)
      if (myReady.value) channel.whisper('ready', { id: props.me.id });
    })
    .leaving((user) => {
      members.value = members.value.filter((m) => m.id !== user.id);
      if (user.id === opponentId.value) { oppReady.value = false; oppSnap.value = null; if (gamePhase.value === 'playing') endGame('win'); }
      refreshPhase();
    })
    .listenForWhisper('ready', () => { oppReady.value = true; maybeStart(); })
    .listenForWhisper('go', (e) => startCountdown(e.at))
    .listenForWhisper('garbage', (e) => { if (engine && gamePhase.value === 'playing') { engine.receiveGarbage(e.n); garbageIn.value = engine.garbageQueue; } })
    .listenForWhisper('board', (e) => { oppSnap.value = e.snap; renderOpp(); })
    .listenForWhisper('dead', () => { if (gamePhase.value === 'playing') endGame('win'); })
    .error(() => { error.value = '실시간 연결에 실패했어요(로그인/네트워크 확인).'; });
}

// ── 준비 → 카운트다운 → 시작 ────────────────────────
function ready() {
  if (result.value) resetForRematch();
  myReady.value = true;
  channel?.whisper('ready', { id: props.me.id });
  maybeStart();
}

function maybeStart() {
  if (isHost.value && myReady.value && oppReady.value && (gamePhase.value === 'ready' || gamePhase.value === 'result')) {
    const at = Date.now() + 3200;
    channel.whisper('go', { at });
    startCountdown(at);
  }
}

function startCountdown(at) {
  gamePhase.value = 'countdown';
  const tick = () => {
    const remain = Math.ceil((at - Date.now()) / 1000);
    countdown.value = remain > 0 ? remain : '시작!';
    if (Date.now() >= at) { beginGame(); return; }
    setTimeout(tick, 100);
  };
  tick();
}

function beginGame() {
  engine = new TetrisEngine({
    onLineClear: (n, eng) => { myLines.value = eng.lines; if (n > 0) channel?.whisper('garbage', { n }); garbageIn.value = eng.garbageQueue; },
    onTopOut: () => { channel?.whisper('dead', {}); endGame('lose'); },
    onChange: (eng) => { myLines.value = eng.lines; garbageIn.value = eng.garbageQueue; },
  });
  result.value = null;
  myReady.value = false;
  oppReady.value = false;
  gamePhase.value = 'playing';
  lastTs = 0; dropAcc = 0;
  window.addEventListener('keydown', handleKey);
  snapTimer = setInterval(sendSnapshot, 150);
  rafId = requestAnimationFrame(loop);
  nextTick(renderAll);
}

function endGame(outcome) {
  result.value = outcome;
  gamePhase.value = 'result';
  stopGameLoop();
  renderAll();
}

function stopGameLoop() {
  if (rafId) cancelAnimationFrame(rafId), (rafId = null);
  if (snapTimer) clearInterval(snapTimer), (snapTimer = null);
  window.removeEventListener('keydown', handleKey);
}

function resetForRematch() {
  result.value = null;
  oppSnap.value = null;
  gamePhase.value = playerIds.value.length >= 2 ? 'ready' : 'waiting';
}

// ── 게임 루프 · 입력 ────────────────────────────────
function dropInterval() { return Math.max(120, 800 - myLines.value * 18); }

function loop(ts) {
  if (gamePhase.value !== 'playing' || !engine) return;
  if (!lastTs) lastTs = ts;
  dropAcc += ts - lastTs;
  lastTs = ts;
  while (dropAcc >= dropInterval()) {
    dropAcc -= dropInterval();
    engine.softDrop();
    if (engine.gameOver) break;
  }
  renderMine();
  rafId = requestAnimationFrame(loop);
}

function handleKey(e) {
  if (gamePhase.value !== 'playing' || !engine) return;
  const k = e.key;
  const handled = ['ArrowLeft', 'ArrowRight', 'ArrowDown', 'ArrowUp', ' ', 'z', 'Z', 'x', 'X', 'Shift', 'c', 'C'];
  if (!handled.includes(k)) return;
  e.preventDefault();
  if (k === 'ArrowLeft') engine.move(-1, 0);
  else if (k === 'ArrowRight') engine.move(1, 0);
  else if (k === 'ArrowDown') { engine.softDrop(); dropAcc = 0; }
  else if (k === 'ArrowUp' || k === 'x' || k === 'X') engine.rotate(1);
  else if (k === 'z' || k === 'Z') engine.rotate(-1);
  else if (k === ' ') { engine.hardDrop(); dropAcc = 0; }
  else if (k === 'Shift' || k === 'c' || k === 'C') engine.holdPiece();
  renderMine();
}

// ── 렌더 ────────────────────────────────────────────
function renderMine() {
  const ctx = myCanvas.value?.getContext('2d');
  if (ctx && engine) drawBoard(ctx, engine, MY_CELL);
  const nctx = nextCanvas.value?.getContext('2d');
  if (nctx && engine) drawNext(nctx, engine.queue[0], 16);
}
function renderOpp() {
  const ctx = oppCanvas.value?.getContext('2d');
  if (ctx) drawSnapshot(ctx, oppSnap.value, OPP_CELL);
}
function renderAll() { renderMine(); renderOpp(); }

function sendSnapshot() {
  if (engine && channel) channel.whisper('board', { snap: engine.serialize() });
}

// ── 방 나가기/링크 ──────────────────────────────────
function leaveRoom() {
  stopGameLoop();
  if (echo && roomCode.value) echo.leave(CHANNEL_PREFIX + roomCode.value);
  channel = null; engine = null;
  members.value = []; oppSnap.value = null; result.value = null;
  myReady.value = oppReady.value = false;
  gamePhase.value = 'waiting';
  view.value = 'lobby';
  const url = new URL(window.location.href);
  url.searchParams.delete('room');
  window.history.replaceState({}, '', url);
}

function copyLink() {
  navigator.clipboard?.writeText(window.location.href);
  copied.value = true;
  setTimeout(() => { copied.value = false; }, 1500);
}

onMounted(() => {
  const code = new URL(window.location.href).searchParams.get('room');
  if (code) enterRoom(code.toUpperCase());
});

onBeforeUnmount(() => {
  stopGameLoop();
  if (matching.value) cancelMatchmaking();
  if (echo && roomCode.value) echo.leave(CHANNEL_PREFIX + roomCode.value);
});
</script>
