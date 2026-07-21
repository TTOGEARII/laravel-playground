<template>
  <div class="tv-root">
    <header class="tv-head">
      <a :href="homeUrl" class="tv-back">← 미니게임</a>
      <h1>테트리스 대전 <small>실시간</small></h1>
    </header>

    <!-- 로비: 방 만들기 / 코드로 입장 -->
    <section v-if="view === 'lobby'" class="tv-lobby">
      <p class="tv-lead">친구와 1:1 실시간 대전. 방을 만들어 링크를 공유하거나, 코드로 입장하세요.</p>
      <div class="tv-lobby-actions">
        <button class="tv-btn tv-btn-primary" :disabled="busy" @click="createRoom">방 만들기</button>
        <div class="tv-join">
          <input v-model.trim="joinCode" maxlength="6" placeholder="방 코드 6자리" class="tv-input" @keyup.enter="joinRoom" />
          <button class="tv-btn" :disabled="busy || joinCode.length < 4" @click="joinRoom">입장</button>
        </div>
      </div>
      <p v-if="error" class="tv-error">{{ error }}</p>
    </section>

    <!-- 방: presence 멤버 + 공유 링크 + (다음 단계에서 게임) -->
    <section v-else class="tv-room">
      <div class="tv-room-bar">
        <span class="tv-code">방 <b>{{ roomCode }}</b></span>
        <button class="tv-btn tv-btn-sm" @click="copyLink">링크 복사</button>
        <span v-if="copied" class="tv-copied">복사됨!</span>
        <button class="tv-btn tv-btn-sm tv-leave" @click="leaveRoom">나가기</button>
      </div>

      <div class="tv-members">
        <div class="tv-members-title">참가자 ({{ members.length }})</div>
        <ul>
          <li v-for="m in members" :key="m.id" :class="{ 'is-me': m.id === me.id }">
            <span class="tv-role" :class="roleOf(m) === '관전' ? 'is-spec' : 'is-player'">{{ roleOf(m) }}</span>
            {{ m.name }}<span v-if="m.id === me.id"> (나)</span>
          </li>
        </ul>
        <p v-if="members.length < 2" class="tv-waiting">상대를 기다리는 중… 링크를 공유하세요.</p>
      </div>

      <!-- 실시간 배관 검증용 핑(다음 단계에서 게임 보드로 교체) -->
      <div class="tv-pingbox">
        <button class="tv-btn tv-btn-sm" :disabled="members.length < 2" @click="sendPing">👋 핑 보내기</button>
        <div class="tv-pinglog">
          <div v-for="(p, i) in pings" :key="i">{{ p }}</div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { createEcho } from '../../../echo.js';

const props = defineProps({
  me: { type: Object, required: true }, // { id, name }
  homeUrl: { type: String, default: '/mini-game' },
  createRoomUrl: { type: String, required: true },
  csrf: { type: String, required: true },
});

const view = ref('lobby');
const busy = ref(false);
const error = ref('');
const joinCode = ref('');
const roomCode = ref('');
const members = ref([]);
const copied = ref(false);
const pings = ref([]);

let echo = null;
let channel = null;

const CHANNEL_PREFIX = 'tetris-room.';

// 입장 순서(id 오름차순) 앞 2명이 참가자, 나머지는 관전.
const sortedIds = computed(() => members.value.map((m) => m.id).sort((a, b) => a - b));
function roleOf(m) {
  return sortedIds.value.indexOf(m.id) < 2 ? '참가자' : '관전';
}

async function createRoom() {
  busy.value = true;
  error.value = '';
  try {
    const res = await fetch(props.createRoomUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': props.csrf, Accept: 'application/json' },
    });
    if (!res.ok) throw new Error('방 생성 실패');
    const { data } = await res.json();
    enterRoom(data.code);
  } catch (e) {
    error.value = '방을 만들지 못했어요. 잠시 후 다시 시도해주세요.';
  } finally {
    busy.value = false;
  }
}

function joinRoom() {
  const code = joinCode.value.toUpperCase();
  if (code.length < 4) return;
  enterRoom(code);
}

function enterRoom(code) {
  roomCode.value = code;
  view.value = 'room';
  // 공유/새로고침 대응: URL 에 ?room= 반영
  const url = new URL(window.location.href);
  url.searchParams.set('room', code);
  window.history.replaceState({}, '', url);

  echo = createEcho();
  channel = echo.join(CHANNEL_PREFIX + code)
    .here((users) => { members.value = users; })
    .joining((user) => { if (!members.value.some((m) => m.id === user.id)) members.value.push(user); })
    .leaving((user) => { members.value = members.value.filter((m) => m.id !== user.id); })
    .listenForWhisper('ping', (e) => {
      pings.value.unshift(`👋 ${e.from} 님의 핑 (${new Date().toLocaleTimeString()})`);
      pings.value = pings.value.slice(0, 8);
    })
    .error((err) => { console.error('presence error', err); error.value = '실시간 연결에 실패했어요(로그인/네트워크 확인).'; });
}

function sendPing() {
  channel?.whisper('ping', { from: props.me.name });
  pings.value.unshift(`나 → 상대에게 핑 보냄`);
  pings.value = pings.value.slice(0, 8);
}

function leaveRoom() {
  if (echo && roomCode.value) echo.leave(CHANNEL_PREFIX + roomCode.value);
  channel = null;
  members.value = [];
  pings.value = [];
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
  if (echo && roomCode.value) echo.leave(CHANNEL_PREFIX + roomCode.value);
});
</script>
