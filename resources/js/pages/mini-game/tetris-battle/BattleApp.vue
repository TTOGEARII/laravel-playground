<template>
  <div class="tb-root">
    <header class="tb-head">
      <a :href="homeUrl" class="tb-back">← 미니게임</a>
      <h1>테트리스 배틀로얄 <small>최대 {{ maxPlayers }}인</small></h1>
    </header>

    <!-- 로비 -->
    <section v-if="view === 'lobby'" class="tb-lobby">
      <p class="tb-lead">여러 명이 동시에! 줄을 지워 상대에게 쓰레기 줄을 보내고, 마지막까지 살아남으세요.</p>
      <div v-if="matching" class="tb-matching"><span class="tb-spin"></span> 방에 들어가는 중…</div>
      <div class="tb-lobby-actions">
        <button class="tb-btn tb-btn-primary tb-quick" :disabled="busy || matching" @click="quickMatch">⚡ 빠른 대전</button>
        <div class="tb-or">또는</div>
        <button class="tb-btn" :disabled="busy || matching" @click="createRoom">방 만들기 (친구 초대)</button>
        <div class="tb-join">
          <input v-model.trim="joinCode" maxlength="6" placeholder="방 코드 6자리" class="tb-input" @keyup.enter="joinRoom" />
          <button class="tb-btn" :disabled="busy || joinCode.length < 4" @click="joinRoom">입장</button>
        </div>
      </div>
      <p v-if="error" class="tb-error">{{ error }}</p>
    </section>

    <!-- 방 -->
    <section v-else class="tb-room">
      <div class="tb-room-bar">
        <span class="tb-code">방 <b>{{ roomCode }}</b></span>
        <button class="tb-btn tb-btn-sm" @click="copyLink">링크 복사</button>
        <span v-if="copied" class="tb-copied">복사됨!</span>
        <span class="tb-count">{{ members.length }}/{{ maxPlayers }}명</span>
        <button class="tb-btn tb-btn-sm tb-leave" @click="leaveRoom">나가기</button>
      </div>

      <!-- 대기실 -->
      <div v-if="gamePhase === 'waiting'" class="tb-waiting">
        <div class="tb-members-title">참가자 ({{ members.length }})</div>
        <ul class="tb-members">
          <li v-for="m in sortedMembers" :key="m.id" :class="{ 'is-me': m.id === me.id }">
            <span class="tb-udot"></span>{{ m.name }}<span v-if="m.id === hostId" class="tb-host">방장</span>
          </li>
        </ul>
        <p v-if="members.length < 2" class="tb-hint">최소 2명이 모이면 시작해요. 위 “링크 복사”로 친구를 초대하세요!</p>
        <template v-else>
          <p v-if="autoStartIn !== null" class="tb-hint">잠시 후 자동 시작… <b>{{ autoStartIn }}</b></p>
          <button v-if="isHost" class="tb-btn tb-btn-primary" @click="hostStart">지금 시작 ({{ startablePlayers }}명)</button>
          <p v-else class="tb-hint">방장이 시작하기를 기다리는 중…</p>
        </template>
      </div>

      <!-- 게임/결과 -->
      <template v-else>
        <div v-if="gamePhase === 'result'" class="tb-result">
          <span class="tb-trophy">🏆</span> 우승 <b>{{ winnerName || '—' }}</b>
          <span v-if="myPlacement" class="tb-my-place"> · 나 {{ myPlacement }}위</span>
          <button class="tb-btn tb-btn-sm tb-btn-primary" @click="backToWaiting">다시</button>
        </div>
        <div v-else-if="!amPlayer" class="tb-result">👁 관전 중 — 생존 {{ aliveCount }}명</div>

        <div class="tb-arena">
          <!-- 내 보드 -->
          <div v-if="amPlayer" class="tb-me-side">
            <div class="tb-board-label">나 <b>{{ me.name }}</b><span v-if="garbageIn > 0" class="tb-gwarn">⚠ {{ garbageIn }}</span></div>
            <div class="tb-canvas-wrap">
              <canvas ref="myCanvas" :width="COLS * MY_CELL" :height="ROWS * MY_CELL" class="tb-canvas"></canvas>
              <div v-if="gamePhase === 'countdown'" class="tb-overlay"><div class="tb-count-big">{{ countdown }}</div></div>
              <div v-else-if="gamePhase === 'dead'" class="tb-overlay">
                <div class="tb-place">{{ myPlacement }}위</div><div class="tb-place-sub">탈락 · 관전 중</div>
              </div>
              <div v-else-if="gamePhase === 'result'" class="tb-overlay">
                <div class="tb-place" :class="{ 'is-win': myPlacement === 1 }">{{ myPlacement === 1 ? '🏆 우승!' : myPlacement + '위' }}</div>
              </div>
            </div>
            <div class="tb-next-row">
              <span class="tb-mini-label">홀드</span>
              <canvas ref="holdCanvas" :width="4 * 14" :height="4 * 14" class="tb-mini"></canvas>
              <span class="tb-mini-label">다음</span>
              <canvas ref="nextCanvas" :width="4 * 14" :height="4 * 14" class="tb-mini"></canvas>
              <span class="tb-alive">생존 {{ aliveCount }}</span>
            </div>
          </div>

          <!-- 상대 보드 그리드 -->
          <div class="tb-opp-grid" :class="'cols-' + gridCols">
            <div v-for="pid in opponentIds" :key="pid" class="tb-opp" :class="{ 'is-dead': !isAlive(pid) }">
              <div class="tb-opp-label">{{ nameOf(pid) }}<span v-if="!isAlive(pid)" class="tb-opp-dead">탈락</span></div>
              <canvas :ref="(el) => setOppCanvas(pid, el)" :width="COLS * OPP_CELL" :height="ROWS * OPP_CELL" class="tb-canvas is-opp"></canvas>
            </div>
          </div>
        </div>

        <!-- 모바일 가상 키패드(참가자·플레이 중) -->
        <div v-if="isTouch && amPlayer && gamePhase === 'playing'" class="tb-touch">
          <div class="tb-tc-group">
            <button class="tb-tc-btn" @touchstart.prevent="pressStart('left')" @touchend.prevent="pressEnd" @touchcancel.prevent="pressEnd" @mousedown.prevent="pressStart('left')" @mouseup.prevent="pressEnd" @mouseleave="pressEnd">◀</button>
            <button class="tb-tc-btn" @touchstart.prevent="pressStart('softdrop')" @touchend.prevent="pressEnd" @touchcancel.prevent="pressEnd" @mousedown.prevent="pressStart('softdrop')" @mouseup.prevent="pressEnd" @mouseleave="pressEnd">▼</button>
            <button class="tb-tc-btn" @touchstart.prevent="pressStart('right')" @touchend.prevent="pressEnd" @touchcancel.prevent="pressEnd" @mousedown.prevent="pressStart('right')" @mouseup.prevent="pressEnd" @mouseleave="pressEnd">▶</button>
          </div>
          <div class="tb-tc-group">
            <button class="tb-tc-btn" @touchstart.prevent="doAction('rotate')" @mousedown.prevent="doAction('rotate')">↻</button>
            <button class="tb-tc-btn tb-tc-wide" @touchstart.prevent="doAction('hold')" @mousedown.prevent="doAction('hold')">HOLD</button>
            <button class="tb-tc-btn tb-tc-hard" @touchstart.prevent="doAction('harddrop')" @mousedown.prevent="doAction('harddrop')">⤓</button>
          </div>
        </div>
      </template>
      <p v-if="error" class="tb-error">{{ error }}</p>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { createEcho } from '../../../echo.js';
import { TetrisEngine, COLS, ROWS } from '../tetris-versus/engine.js';
import { drawBoard, drawSnapshot, drawNext } from '../tetris-versus/render.js';

const props = defineProps({
  me: { type: Object, required: true },
  homeUrl: { type: String, default: '/mini-game' },
  createRoomUrl: { type: String, required: true },
  matchmakeUrl: { type: String, required: true },
  csrf: { type: String, required: true },
  maxPlayers: { type: Number, default: 6 },
});

const CHANNEL_PREFIX = 'tetris-room.';
const isTouch = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;

// 셀 크기(반응형): 내 보드는 크게, 상대 미니보드는 인원수만큼 여러 개라 작게
const MY_CELL = ref(20);
const OPP_CELL = ref(9);
function computeCellSizes() {
  const w = window.innerWidth;
  if (w >= 900) { MY_CELL.value = 22; OPP_CELL.value = 10; }
  else if (w >= 560) { MY_CELL.value = 18; OPP_CELL.value = 9; }
  else { MY_CELL.value = 14; OPP_CELL.value = 7; }
}
computeCellSizes();

// 로비/방
const view = ref('lobby');
const busy = ref(false);
const error = ref('');
const joinCode = ref('');
const roomCode = ref('');
const members = ref([]);
const copied = ref(false);
const matching = ref(false);
const isQuickMatch = ref(false);

// 게임
const gamePhase = ref('waiting'); // waiting | countdown | playing | dead | result
const countdown = ref(3);
const players = ref([]);   // 이번 판 참가자 id(시작 시 고정)
const aliveIds = ref([]);  // 생존 id
const myLines = ref(0);
const garbageIn = ref(0);
const myPlacement = ref(null);
const winnerName = ref('');
const autoStartIn = ref(null);

const myCanvas = ref(null);
const nextCanvas = ref(null);
const holdCanvas = ref(null);
const oppCanvases = {}; // pid -> canvas el (비반응)
const oppSnaps = {};    // pid -> 스냅샷 (비반응)

let echo = null;
let channel = null;
let engine = null;
let rafId = null;
let lastTs = 0;
let dropAcc = 0;
let snapTimer = null;
let repeatTimer = null;
let gatherTimer = null;
let gatherTick = null;
let playStart = 0; // 이번 판 시작 시각(ms) — 시간 가속 계산용

// ── 멤버/역할 ──
const sortedMembers = computed(() => [...members.value].sort((a, b) => a.id - b.id));
const sortedIds = computed(() => sortedMembers.value.map((m) => m.id));
const hostId = computed(() => sortedIds.value[0] ?? null);
const isHost = computed(() => props.me.id === hostId.value);
const amPlayer = computed(() => players.value.includes(props.me.id));
const opponentIds = computed(() => players.value.filter((id) => id !== props.me.id));
const aliveCount = computed(() => aliveIds.value.length);
const startablePlayers = computed(() => Math.min(members.value.length, props.maxPlayers));
const gridCols = computed(() => {
  const n = opponentIds.value.length;
  return n <= 1 ? 1 : n <= 4 ? 2 : 3;
});
function nameOf(id) { return members.value.find((m) => m.id === id)?.name ?? '게스트'; }
function isAlive(id) { return aliveIds.value.includes(id); }
function setOppCanvas(pid, el) { if (el) { oppCanvases[pid] = el; renderOpp(pid); } else { delete oppCanvases[pid]; } }

// ── 매칭/방 입장 ──
function mmHeaders() {
  return { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': props.csrf, Accept: 'application/json' };
}
async function quickMatch() {
  matching.value = true; error.value = '';
  try {
    const res = await fetch(props.matchmakeUrl, { method: 'POST', headers: mmHeaders() });
    const { data } = await res.json();
    isQuickMatch.value = true;
    enterRoom(data.code);
  } catch { error.value = '매칭에 실패했어요. 잠시 후 다시 시도해주세요.'; }
  finally { matching.value = false; }
}
async function createRoom() {
  busy.value = true; error.value = '';
  try {
    const res = await fetch(props.createRoomUrl, { method: 'POST', headers: mmHeaders() });
    if (!res.ok) throw new Error();
    const { data } = await res.json();
    isQuickMatch.value = false;
    enterRoom(data.code);
  } catch { error.value = '방을 만들지 못했어요.'; }
  finally { busy.value = false; }
}
function joinRoom() {
  const code = joinCode.value.toUpperCase();
  if (code.length < 4) return;
  isQuickMatch.value = false;
  enterRoom(code);
}

function enterRoom(code) {
  roomCode.value = code;
  view.value = 'room';
  const url = new URL(window.location.href);
  url.searchParams.set('room', code);
  window.history.replaceState({}, '', url);

  echo = createEcho();
  channel = echo.join(CHANNEL_PREFIX + code)
    .here((users) => { members.value = users; onMembersChanged(); })
    .joining((user) => { if (!members.value.some((m) => m.id === user.id)) members.value.push(user); onMembersChanged(); })
    .leaving((user) => {
      members.value = members.value.filter((m) => m.id !== user.id);
      handleDeparture(user.id);
      onMembersChanged();
    })
    .listenForWhisper('bstart', (e) => onStart(e))
    .listenForWhisper('bboard', (e) => { if (e.from !== props.me.id) { oppSnaps[e.from] = e.snap; renderOpp(e.from); } })
    .listenForWhisper('bgarbage', (e) => {
      if (e.target === props.me.id && gamePhase.value === 'playing' && engine) {
        engine.receiveGarbage(e.n); garbageIn.value = engine.garbageQueue;
      }
    })
    .listenForWhisper('bdead', (e) => registerDeath(e.from))
    .error(() => { error.value = '실시간 연결에 실패했어요(네트워크 확인).'; });
}

// ── 시작 코디네이션 ──
function onMembersChanged() {
  if (gamePhase.value !== 'waiting') return;
  if (!(isQuickMatch.value && isHost.value)) return; // 자동시작은 빠른대전 방장만
  if (members.value.length >= props.maxPlayers) { clearGather(); hostStart(); return; }
  if (members.value.length >= 2 && gatherTimer === null) startGather();
  else if (members.value.length < 2) clearGather();
}
function startGather() {
  let remain = 6;
  autoStartIn.value = remain;
  gatherTick = setInterval(() => { remain -= 1; autoStartIn.value = Math.max(remain, 0); }, 1000);
  gatherTimer = setTimeout(() => { clearGather(); if (members.value.length >= 2 && gamePhase.value === 'waiting') hostStart(); }, 6000);
}
function clearGather() {
  if (gatherTimer) { clearTimeout(gatherTimer); gatherTimer = null; }
  if (gatherTick) { clearInterval(gatherTick); gatherTick = null; }
  autoStartIn.value = null;
}
function hostStart() {
  if (!isHost.value || gamePhase.value !== 'waiting' || members.value.length < 2) return;
  clearGather();
  const ps = sortedIds.value.slice(0, props.maxPlayers);
  const at = Date.now() + 3500;
  channel.whisper('bstart', { at, players: ps });
  onStart({ at, players: ps });
}
function onStart(e) {
  if (gamePhase.value === 'playing' || gamePhase.value === 'countdown') return;
  clearGather();
  players.value = e.players;
  aliveIds.value = [...e.players];
  myPlacement.value = null;
  winnerName.value = '';
  Object.keys(oppSnaps).forEach((k) => delete oppSnaps[k]);
  startCountdown(e.at);
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
  if (!amPlayer.value) { gamePhase.value = 'playing'; nextTick(renderAllOpp); return; } // 늦게 온 사람은 관전
  engine = new TetrisEngine({
    onLineClear: (n, eng) => { myLines.value = eng.lines; if (n > 0) sendGarbage(n); garbageIn.value = eng.garbageQueue; },
    onTopOut: () => onMyTopOut(),
    onChange: (eng) => { myLines.value = eng.lines; garbageIn.value = eng.garbageQueue; },
  });
  myLines.value = 0; garbageIn.value = 0;
  gamePhase.value = 'playing';
  lastTs = 0; dropAcc = 0; playStart = Date.now();
  window.addEventListener('keydown', handleKey);
  snapTimer = setInterval(sendSnapshot, 150);
  rafId = requestAnimationFrame(loop);
  nextTick(() => { renderMine(); renderAllOpp(); });
}

// ── 가비지(랜덤 생존자 1명) ──
function sendGarbage(n) {
  const targets = aliveIds.value.filter((id) => id !== props.me.id);
  if (targets.length === 0) return;
  const target = targets[Math.floor(Math.random() * targets.length)];
  channel?.whisper('bgarbage', { n, from: props.me.id, target });
}

// ── 탈락/승리 ──
function onMyTopOut() {
  if (gamePhase.value !== 'playing' || !amPlayer.value) return;
  myPlacement.value = aliveIds.value.length; // 죽는 순간 생존자 수 = 내 등수
  removeAlive(props.me.id);
  channel?.whisper('bdead', { from: props.me.id });
  gamePhase.value = 'dead';
  stopMyLoop();
  checkWinner();
  renderAllOpp();
}
function registerDeath(id) { removeAlive(id); checkWinner(); }
function handleDeparture(id) {
  if ((gamePhase.value === 'playing' || gamePhase.value === 'dead' || gamePhase.value === 'countdown')
      && players.value.includes(id) && aliveIds.value.includes(id)) {
    removeAlive(id);
    checkWinner();
  }
}
function removeAlive(id) { aliveIds.value = aliveIds.value.filter((x) => x !== id); }
function checkWinner() {
  if (gamePhase.value === 'waiting' || gamePhase.value === 'result') return;
  if (aliveIds.value.length > 1) return;
  const winnerId = aliveIds.value[0] ?? null;
  winnerName.value = winnerId !== null ? nameOf(winnerId) : '';
  if (winnerId === props.me.id) myPlacement.value = 1;
  endGame();
}
function endGame() {
  stopMyLoop();
  gamePhase.value = 'result';
  renderAllOpp();
}

// ── 루프/입력 ──
// 낙하 간격: 지운 줄 + 시간 경과(배틀은 더 공격적) 둘 다로 가속 → 후반엔 매우 빨라져 자연스럽게 결착.
function dropInterval() {
  const elapsed = playStart ? Date.now() - playStart : 0;
  const timeSpeedup = Math.min(Math.floor(elapsed / 6000) * 50, 720); // 6초마다 -50ms(최대 -720)
  return Math.max(80, 800 - myLines.value * 18 - timeSpeedup);
}
function loop(ts) {
  if (gamePhase.value !== 'playing' || !engine) return;
  if (!lastTs) lastTs = ts;
  dropAcc += ts - lastTs; lastTs = ts;
  while (dropAcc >= dropInterval()) { dropAcc -= dropInterval(); engine.softDrop(); if (engine.gameOver) break; }
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
function doAction(act) {
  if (gamePhase.value !== 'playing' || !engine) return;
  if (act === 'left') engine.move(-1, 0);
  else if (act === 'right') engine.move(1, 0);
  else if (act === 'softdrop') { engine.softDrop(); dropAcc = 0; }
  else if (act === 'rotate') engine.rotate(1);
  else if (act === 'hold') engine.holdPiece();
  else if (act === 'harddrop') { engine.hardDrop(); dropAcc = 0; }
  renderMine();
}
function pressStart(act) {
  doAction(act);
  if (act === 'left' || act === 'right' || act === 'softdrop') {
    clearInterval(repeatTimer);
    repeatTimer = setInterval(() => doAction(act), 110);
  }
}
function pressEnd() { if (repeatTimer) { clearInterval(repeatTimer); repeatTimer = null; } }

// ── 렌더 ──
function renderMine() {
  const ctx = myCanvas.value?.getContext('2d');
  if (ctx && engine) drawBoard(ctx, engine, MY_CELL.value);
  const nctx = nextCanvas.value?.getContext('2d');
  if (nctx && engine) drawNext(nctx, engine.queue[0], 14);
  const hctx = holdCanvas.value?.getContext('2d');
  if (hctx && engine) drawNext(hctx, engine.hold, 14); // 홀드 미리보기(null 이면 빈칸)
}
function renderOpp(pid) {
  const el = oppCanvases[pid];
  if (el) drawSnapshot(el.getContext('2d'), oppSnaps[pid], OPP_CELL.value);
}
function renderAllOpp() { opponentIds.value.forEach(renderOpp); }
function sendSnapshot() { if (engine && channel) channel.whisper('bboard', { snap: engine.serialize(), from: props.me.id }); }

// ── 방 나가기/다시/링크 ──
function stopMyLoop() {
  if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
  if (snapTimer) { clearInterval(snapTimer); snapTimer = null; }
  pressEnd();
  window.removeEventListener('keydown', handleKey);
}
function backToWaiting() {
  stopMyLoop();
  engine = null;
  gamePhase.value = 'waiting';
  myPlacement.value = null;
  myLines.value = 0; garbageIn.value = 0;
  players.value = [];
  aliveIds.value = [];
  Object.keys(oppSnaps).forEach((k) => delete oppSnaps[k]);
  onMembersChanged();
}
function leaveRoom() {
  stopMyLoop();
  clearGather();
  if (echo && roomCode.value) echo.leave(CHANNEL_PREFIX + roomCode.value);
  channel = null; engine = null;
  members.value = []; players.value = []; aliveIds.value = [];
  myPlacement.value = null;
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
function onResize() {
  const pm = MY_CELL.value, po = OPP_CELL.value;
  computeCellSizes();
  if (MY_CELL.value !== pm || OPP_CELL.value !== po) nextTick(() => { renderMine(); renderAllOpp(); });
}

onMounted(() => {
  window.addEventListener('resize', onResize);
  const code = new URL(window.location.href).searchParams.get('room');
  if (code) { isQuickMatch.value = false; enterRoom(code.toUpperCase()); }
});
onBeforeUnmount(() => {
  stopMyLoop();
  clearGather();
  window.removeEventListener('resize', onResize);
  if (echo && roomCode.value) echo.leave(CHANNEL_PREFIX + roomCode.value);
});
</script>
