<template>
  <div class="mw-chat">
    <!-- 좌측: 채팅 패널 -->
    <section class="mw-chat-pane">
      <!-- 상단 바: 뒤로가기 + 메시지 수 + 호감도 게이지 -->
      <header class="mw-bar">
        <div class="mw-bar-top">
          <a :href="charactersUrl" class="mw-back" aria-label="목록으로">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </a>
          <h1 class="mw-bar-name">{{ character?.name || '캐릭터' }}</h1>
          <div class="mw-count">{{ messageCount }}</div>
        </div>
        <div class="mw-affinity">
          <span class="mw-heart">♥</span>
          <div class="mw-affinity-track"><div class="mw-affinity-fill" :style="{ width: affinity + '%' }"></div></div>
          <span class="mw-affinity-val">{{ affinity }}%</span>
        </div>
      </header>

      <!-- 메시지 스트림 -->
      <main class="mw-stream" ref="streamEl" @click="skipTyping">
        <div v-if="loadingIntro" class="mw-row character">
          <div class="mw-avatar"><img v-if="hasCharacterImage" :src="characterImageSrc" :alt="character.name" /><span v-else>{{ initial }}</span></div>
          <div class="mw-bubble mw-typing"><span class="mw-dot"></span><span class="mw-dot"></span><span class="mw-dot"></span></div>
        </div>

        <template v-for="(msg, i) in messages" :key="i">
          <!-- 유저 메시지 -->
          <div v-if="msg.role === 'user'" class="mw-row user">
            <div class="mw-bubble user">{{ msg.text }}</div>
          </div>

          <!-- 캐릭터 턴: 지문 + 대사 -->
          <div v-else class="mw-turn">
            <p v-if="narrationOf(i, msg)" class="mw-narration">{{ narrationOf(i, msg) }}<span v-if="isTyping(i) && typing.phase === 'n'" class="mw-caret"></span></p>
            <div v-if="dialogueOf(i, msg) || (isTyping(i) && typing.phase === 't')" class="mw-row character">
              <div class="mw-avatar"><img v-if="hasCharacterImage" :src="characterImageSrc" :alt="character.name" /><span v-else>{{ initial }}</span></div>
              <div class="mw-bubble character">
                <span class="mw-name">{{ character?.name || '캐릭터' }}</span>
                <p class="mw-text">{{ dialogueOf(i, msg) }}<span v-if="isTyping(i) && typing.phase === 't'" class="mw-caret"></span></p>
              </div>
            </div>
          </div>
        </template>

        <!-- 응답 대기 -->
        <div v-if="thinking" class="mw-row character">
          <div class="mw-avatar"><img v-if="hasCharacterImage" :src="characterImageSrc" :alt="character.name" /><span v-else>{{ initial }}</span></div>
          <div class="mw-bubble mw-typing"><span class="mw-dot"></span><span class="mw-dot"></span><span class="mw-dot"></span></div>
        </div>
      </main>

      <!-- 입력 영역 -->
      <footer class="mw-input-wrap">
        <!-- 추천답변 칩 -->
        <div v-if="suggestions.length" class="mw-suggestions">
          <button v-for="(s, si) in suggestions" :key="si" type="button" class="mw-suggestion" @click="useSuggestion(s)">{{ s }}</button>
        </div>

        <!-- 액션 칩 -->
        <div class="mw-actions">
          <button type="button" class="mw-action" :disabled="busy" @click="requestNarration">✦ 상황묘사</button>
          <button type="button" class="mw-action" :disabled="busy" @click="requestSuggestions">⇄ 추천답변</button>
          <span v-if="actionLoading" class="mw-action-loading">불러오는 중…</span>
        </div>

        <div class="mw-input-inner">
          <textarea
            v-model="inputText"
            :placeholder="`${character?.name || '캐릭터'}에게 메시지 보내기`"
            rows="1"
            class="mw-input"
            ref="inputEl"
            @input="autoGrow"
            @keydown.enter.exact="onEnterKey"
          />
          <button type="button" class="mw-send" :disabled="!inputText.trim() || busy" @click="send" aria-label="전송">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6" /></svg>
          </button>
        </div>
      </footer>
    </section>

    <!-- 우측: 캐릭터 일러스트 패널 -->
    <aside class="mw-art" :class="{ 'no-img': !hasCharacterImage }">
      <img v-if="hasCharacterImage" class="mw-art-img" :src="characterImageSrc" :alt="character?.name" />
      <span v-else class="mw-art-initial">{{ initial }}</span>
    </aside>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { myWifeBotChatApi } from './api.js';

const props = defineProps({
  character: { type: Object, default: () => ({}) },
});

const charactersUrl = '/my-wife-bot';

const characterImageSrc = computed(() => {
  const raw = props.character?.image;
  if (!raw || typeof raw !== 'string') return '';
  const trimmed = raw.trim();
  if (!trimmed) return '';
  if (trimmed.startsWith('http://') || trimmed.startsWith('https://') || trimmed.startsWith('//')) return trimmed;
  if (trimmed.startsWith('/')) return window.location.origin + trimmed;
  return trimmed;
});
const hasCharacterImage = computed(() => !!characterImageSrc.value);
const initial = computed(() => (props.character?.name || '?').charAt(0));

const messages = ref([]); // { role, text, narration }
const sessionId = ref('');
const loadingIntro = ref(true);
const inputText = ref('');
const thinking = ref(false);
const actionLoading = ref(false);
const affinity = ref(10);
const suggestions = ref([]);
const streamEl = ref(null);
const inputEl = ref(null);

const messageCount = computed(() => messages.value.length);
const busy = computed(() => thinking.value || actionLoading.value || typing.active);

/* ── 타자기 리빌 ─────────────────────────────────────── */
const typing = reactive({ index: -1, phase: 'n', narration: '', text: '', active: false });
let typingTimer = null;
const TYPE_SPEED = 22; // ms/char

function isTyping(i) {
  return typing.active && typing.index === i;
}
function narrationOf(i, msg) {
  return isTyping(i) ? typing.narration : (msg.narration || '');
}
function dialogueOf(i, msg) {
  return isTyping(i) ? typing.text : (msg.text || '');
}

function startTyping(index) {
  cancelTimer();
  const msg = messages.value[index];
  if (!msg) return;
  const fullN = msg.narration || '';
  const fullT = msg.text || '';
  typing.index = index;
  typing.narration = '';
  typing.text = '';
  typing.phase = fullN ? 'n' : 't';
  typing.active = true;

  let n = 0;
  let t = 0;
  const step = () => {
    if (!typing.active) return;
    if (typing.phase === 'n') {
      if (n < fullN.length) {
        typing.narration = fullN.slice(0, ++n);
        scrollSoon();
        typingTimer = setTimeout(step, TYPE_SPEED);
      } else {
        typing.phase = 't';
        typingTimer = setTimeout(step, fullT ? 160 : 0);
      }
    } else if (t < fullT.length) {
      typing.text = fullT.slice(0, ++t);
      scrollSoon();
      typingTimer = setTimeout(step, TYPE_SPEED);
    } else {
      finishTyping();
    }
  };
  step();
}

function finishTyping() {
  cancelTimer();
  typing.active = false;
  typing.index = -1;
  scrollSoon();
}

function skipTyping() {
  if (!typing.active) return;
  cancelTimer();
  const msg = messages.value[typing.index];
  if (msg) {
    typing.narration = msg.narration || '';
    typing.text = msg.text || '';
    typing.phase = 't';
  }
  finishTyping();
}

function cancelTimer() {
  if (typingTimer) {
    clearTimeout(typingTimer);
    typingTimer = null;
  }
}

/* ── 텍스트 정규화 (JSON 문자열 대비) ───────────────────── */
function normalizeText(msg) {
  if (msg == null) return '';
  if (typeof msg === 'object') {
    const s = msg.message ?? msg.text ?? msg.content ?? msg.intro;
    return typeof s === 'string' ? cleanString(s) : '';
  }
  if (typeof msg !== 'string') return String(msg);
  return cleanString(msg);
}

/**
 * 코드펜스(```json …```)/JSON 스캐폴딩이 섞인 문자열에서 표시할 텍스트만 복구.
 * 정상 JSON이면 파싱, 깨졌으면 intro/message/text/content 값을 정규식으로 추출.
 */
function cleanString(raw) {
  let s = String(raw).trim();
  // 코드펜스 제거
  s = s.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '').trim();

  if (s.startsWith('{')) {
    try {
      const parsed = JSON.parse(s);
      if (parsed && typeof parsed === 'object') {
        const v = parsed.intro ?? parsed.message ?? parsed.text ?? parsed.content;
        if (typeof v === 'string') return v.trim();
      }
    } catch (_) {
      // 깨진/잘린 JSON: 키 값 추출 (닫는 따옴표 유무 모두 대응)
      for (const key of ['intro', 'message', 'text', 'content']) {
        const full = s.match(new RegExp(`"${key}"\\s*:\\s*"((?:[^"\\\\]|\\\\.)*)"`));
        if (full) return full[1].replace(/\\"/g, '"').replace(/\\n/g, '\n').trim();
        const partial = s.match(new RegExp(`"${key}"\\s*:\\s*"(.+)$`, 's'));
        if (partial) return partial[1].replace(/["}\s\`]+$/, '').replace(/\\"/g, '"').replace(/\\n/g, '\n').trim();
      }
    }
  }
  return s;
}

/* ── 액션 ────────────────────────────────────────────── */
// 한글(IME) 조합 중 Enter는 마지막 글자가 아직 확정되지 않았으므로 전송하지 않는다.
// 조합이 끝난 뒤(또는 다시 누른) Enter에서만 전송해 글자 누락/잔류 버그를 막는다.
function onEnterKey(e) {
  if (e.isComposing || e.keyCode === 229) return;
  e.preventDefault();
  send();
}

async function send() {
  const text = inputText.value.trim();
  if (!text || busy.value) return;
  suggestions.value = [];

  if (!sessionId.value) {
    pushCharacter({ text: '세션이 없어 대화를 이어갈 수 없어요. 새로고침 후 다시 말 걸어 주세요.' });
    return;
  }

  messages.value.push({ role: 'user', text });
  inputText.value = '';
  resetInputHeight();
  scrollSoon();

  thinking.value = true;
  try {
    const data = await myWifeBotChatApi.sendMessage(sessionId.value, text);
    if (typeof data?.affinity === 'number') affinity.value = data.affinity;
    pushCharacter({
      text: normalizeText(data?.message?.text ?? data?.message),
      narration: data?.message?.narration || '',
    });
  } catch (_) {
    pushCharacter({ text: '응답을 받지 못했어요. 잠시 후 다시 시도해 주세요.' });
  } finally {
    thinking.value = false;
  }
}

async function requestSuggestions() {
  if (!sessionId.value || busy.value) return;
  actionLoading.value = true;
  suggestions.value = [];
  try {
    suggestions.value = await myWifeBotChatApi.suggestReplies(sessionId.value);
  } catch (_) {
    suggestions.value = [];
  } finally {
    actionLoading.value = false;
  }
}

async function requestNarration() {
  if (!sessionId.value || busy.value) return;
  actionLoading.value = true;
  try {
    const narration = await myWifeBotChatApi.narrate(sessionId.value);
    if (narration) pushCharacter({ narration, text: '' });
  } catch (_) {
    /* 무시 — 상황묘사는 실패해도 대화 흐름엔 영향 없음 */
  } finally {
    actionLoading.value = false;
  }
}

function useSuggestion(s) {
  inputText.value = s;
  suggestions.value = [];
  send();
}

/* 캐릭터 메시지 추가 + 타자기 시작 */
function pushCharacter({ text = '', narration = '' }) {
  messages.value.push({ role: 'character', text, narration });
  const idx = messages.value.length - 1;
  scrollSoon();
  nextTick(() => startTyping(idx));
}

/* ── UI 보조 ─────────────────────────────────────────── */
function scrollToBottom() {
  if (streamEl.value) streamEl.value.scrollTop = streamEl.value.scrollHeight;
}
function scrollSoon() {
  nextTick(() => scrollToBottom());
}
function autoGrow() {
  const el = inputEl.value;
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}
function resetInputHeight() {
  if (inputEl.value) inputEl.value.style.height = 'auto';
}

function getCharacterIdFromUrl() {
  const m = window.location.pathname.match(/\/my-wife-bot\/chat\/([^/]+)/);
  return m ? m[1] : null;
}

// 대화 이어가기용: 브라우저에 캐릭터별 세션 ID 보관
function sessionStorageKey(cid) {
  return `mw_session_${cid}`;
}
function loadStoredSessionId(cid) {
  try {
    return localStorage.getItem(sessionStorageKey(cid)) || null;
  } catch (_) {
    return null;
  }
}
function storeSessionId(cid, sid) {
  try {
    if (cid && sid) localStorage.setItem(sessionStorageKey(cid), String(sid));
  } catch (_) {
    /* localStorage 불가 환경 무시 */
  }
}

onMounted(async () => {
  const cid = props.character?.id ?? getCharacterIdFromUrl();
  let resumed = false;
  try {
    if (cid) {
      const data = await myWifeBotChatApi.initChat(cid, loadStoredSessionId(cid));
      sessionId.value = data.session_id || '';
      storeSessionId(cid, sessionId.value);
      resumed = !!data.resumed;
      if (typeof data.affinity === 'number') affinity.value = data.affinity;
      const list = data.initial_messages || [];
      messages.value = list.map((m) => ({
        role: m?.role || 'character',
        text: normalizeText(m?.text ?? m?.content ?? m),
        narration: m?.narration || '',
      }));
    }
    if (messages.value.length === 0 && props.character?.intro) {
      messages.value = [{ role: 'character', text: String(props.character.intro).trim(), narration: '' }];
    }
    if (messages.value.length === 0) {
      messages.value = [{ role: 'character', text: `안녕하세요, ${props.character?.name || '캐릭터'}이에요. 편하게 말 걸어 주세요.`, narration: '' }];
    }
  } catch (_) {
    messages.value = [{ role: 'character', text: '인트로를 불러오지 못했어요. 편하게 말 걸어 주세요.', narration: '' }];
  } finally {
    loadingIntro.value = false;
  }
  // 이어가는 대화는 즉시 전체 표시, 새 대화는 마지막 인트로만 타자기로 노출.
  nextTick(() => {
    if (resumed) {
      scrollToBottom();
      return;
    }
    const lastIdx = messages.value.length - 1;
    if (lastIdx >= 0 && messages.value[lastIdx].role === 'character') startTyping(lastIdx);
    else scrollToBottom();
  });
});

onBeforeUnmount(cancelTimer);
</script>

<style scoped>
.mw-chat {
  --mw-bg: #0c0c12;
  --mw-pane: #121219;
  --mw-text: #ececf1;
  --mw-muted: #b8b8c4;
  --mw-line: rgba(255, 255, 255, 0.1);
  --mw-accent: #38bdf8;
  --mw-accent-2: #6366f1;

  position: relative;
  display: flex;
  flex-direction: row;
  height: calc(100dvh - 150px);
  min-height: 560px;
  max-height: 900px;
  background: var(--mw-bg);
  border: 1px solid var(--mw-line);
  border-radius: 22px;
  overflow: hidden;
  color: var(--mw-text);
  font-family: Pretendard, -apple-system, system-ui, 'Segoe UI', sans-serif;
}

/* 좌측 채팅 패널 */
.mw-chat-pane {
  flex: 1 1 0;
  min-width: 0;
  display: flex;
  flex-direction: column;
  background: var(--mw-bg);
}

/* 우측 일러스트 패널 */
.mw-art {
  position: relative;
  flex: 0 0 42%;
  max-width: 480px;
  overflow: hidden;
  border-left: 1px solid var(--mw-line);
  background: #0b0b12;
}
.mw-art-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center top;
  display: block;
}
.mw-art.no-img {
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(120% 80% at 50% 30%, rgba(99, 102, 241, 0.4), transparent 60%), #0b0b12;
}
.mw-art-initial { font-size: 5rem; font-weight: 800; color: rgba(255, 255, 255, 0.4); }

/* 상단 바 */
.mw-bar {
  flex-shrink: 0;
  padding: 12px 16px 10px;
  border-bottom: 1px solid var(--mw-line);
  background: rgba(0, 0, 0, 0.2);
}
.mw-bar-top {
  display: flex;
  align-items: center;
  gap: 12px;
}
.mw-back {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid var(--mw-line);
  color: var(--mw-text);
  flex-shrink: 0;
}
.mw-back svg { width: 18px; height: 18px; }
.mw-bar-name {
  flex: 1;
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.mw-count {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--mw-muted);
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid var(--mw-line);
  border-radius: 999px;
  padding: 3px 10px;
}
.mw-affinity {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
}
.mw-heart { color: #f472b6; font-size: 0.85rem; filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.6)); }
.mw-affinity-track {
  flex: 1;
  height: 6px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.45);
  border: 1px solid var(--mw-line);
  overflow: hidden;
}
.mw-affinity-fill {
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, #f472b6, #fb7185);
  transition: width 0.6s ease;
}
.mw-affinity-val { font-size: 0.75rem; font-weight: 600; color: var(--mw-muted); min-width: 34px; text-align: right; }

/* 스트림 */
.mw-stream {
  position: relative;
  z-index: 2;
  flex: 1;
  overflow-y: auto;
  padding: 14px 16px 8px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  scroll-behavior: smooth;
}
.mw-turn { display: flex; flex-direction: column; gap: 10px; }

.mw-narration {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.7;
  color: #d7d7e2;
  font-style: italic;
  text-align: center;
  padding: 2px 8px;
  text-shadow: 0 1px 8px rgba(0, 0, 0, 0.85);
  white-space: pre-wrap;
  word-break: break-word;
}

.mw-row { display: flex; align-items: flex-end; gap: 9px; max-width: 88%; }
.mw-row.character { align-self: flex-start; }
.mw-row.user { align-self: flex-end; }

.mw-avatar {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  overflow: hidden;
  flex-shrink: 0;
  background: rgba(99, 102, 241, 0.3);
  border: 1px solid var(--mw-line);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.95rem;
}
.mw-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }

.mw-bubble {
  padding: 10px 14px;
  border-radius: 16px;
  max-width: 100%;
  backdrop-filter: blur(6px);
}
.mw-bubble.character {
  background: rgba(20, 20, 28, 0.78);
  border: 1px solid var(--mw-line);
  border-bottom-left-radius: 6px;
}
.mw-bubble.user {
  background: linear-gradient(135deg, var(--mw-accent), var(--mw-accent-2));
  color: #fff;
  border-bottom-right-radius: 6px;
  font-size: 0.94rem;
  line-height: 1.55;
  white-space: pre-wrap;
  word-break: break-word;
}
.mw-name { display: block; font-size: 0.72rem; font-weight: 700; color: var(--mw-accent); margin-bottom: 3px; }
.mw-text { margin: 0; font-size: 0.94rem; line-height: 1.6; color: var(--mw-text); white-space: pre-wrap; word-break: break-word; }

.mw-caret {
  display: inline-block;
  width: 2px;
  height: 1em;
  margin-left: 1px;
  vertical-align: text-bottom;
  background: var(--mw-accent);
  animation: mw-blink 0.8s step-end infinite;
}
@keyframes mw-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

.mw-typing { display: flex; gap: 5px; align-items: center; padding: 14px 16px; }
.mw-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--mw-muted); animation: mw-bounce 1.4s ease-in-out infinite both; }
.mw-dot:nth-child(2) { animation-delay: 0.2s; }
.mw-dot:nth-child(3) { animation-delay: 0.4s; }
@keyframes mw-bounce { 0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; } 40% { transform: scale(1); opacity: 1; } }

/* 입력 영역 */
.mw-input-wrap {
  position: relative;
  z-index: 3;
  flex-shrink: 0;
  padding: 10px 14px 14px;
  border-top: 1px solid var(--mw-line);
  background: rgba(0, 0, 0, 0.2);
}
.mw-suggestions { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
.mw-suggestion {
  text-align: left;
  padding: 9px 13px;
  border-radius: 12px;
  background: rgba(20, 20, 28, 0.85);
  border: 1px solid rgba(56, 189, 248, 0.35);
  color: var(--mw-text);
  font-size: 0.875rem;
  cursor: pointer;
  transition: background 0.15s;
}
.mw-suggestion:hover { background: rgba(56, 189, 248, 0.18); }

.mw-actions { display: flex; align-items: center; gap: 7px; margin-bottom: 8px; }
.mw-action {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 12px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.4);
  border: 1px solid var(--mw-line);
  color: var(--mw-muted);
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.mw-action:hover:not(:disabled) { color: var(--mw-text); border-color: rgba(56, 189, 248, 0.5); }
.mw-action:disabled { opacity: 0.45; cursor: not-allowed; }
.mw-action-loading { font-size: 0.72rem; color: var(--mw-muted); }

.mw-input-inner {
  display: flex;
  align-items: flex-end;
  gap: 9px;
  background: rgba(20, 20, 28, 0.9);
  border: 1px solid var(--mw-line);
  border-radius: 18px;
  padding: 8px 8px 8px 14px;
}
.mw-input {
  flex: 1;
  min-height: 24px;
  max-height: 120px;
  background: none;
  border: none;
  color: var(--mw-text);
  font-size: 0.94rem;
  font-family: inherit;
  resize: none;
  outline: none;
  line-height: 1.5;
}
.mw-input::placeholder { color: var(--mw-muted); opacity: 0.7; }
.mw-send {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--mw-accent), var(--mw-accent-2));
  border: none;
  color: #fff;
  cursor: pointer;
  transition: opacity 0.15s, transform 0.1s;
}
.mw-send svg { width: 19px; height: 19px; }
.mw-send:hover:not(:disabled) { transform: scale(1.05); }
.mw-send:disabled { opacity: 0.4; cursor: not-allowed; }

/* 좁은 화면: 일러스트를 채팅 전체의 배경으로 깔고 살짝 어둡게 → 글자 가독성 확보 */
@media (max-width: 860px) {
  .mw-chat { flex-direction: column; height: calc(100dvh - 110px); border-radius: 16px; }

  /* 일러스트 패널을 채팅 컨테이너 뒤로 절대배치 (배너 대신 배경) */
  .mw-art {
    position: absolute;
    inset: 0;
    z-index: 0;
    flex: none;
    max-width: none;
    border-left: none;
    pointer-events: none; /* 채팅 조작을 방해하지 않게 */
  }
  /* 이미지 위에 어두운 스크림 — 위는 덜, 아래(입력부)로 갈수록 더 어둡게 */
  .mw-art::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
      180deg,
      rgba(8, 8, 14, 0.45) 0%,
      rgba(8, 8, 14, 0.55) 45%,
      rgba(8, 8, 14, 0.78) 100%
    );
  }

  /* 채팅 패널을 투명 처리해 뒤의 이미지가 비치게 */
  .mw-chat-pane { min-height: 0; background: transparent; position: relative; z-index: 1; }

  /* 상단 바·입력부는 배경 위에서도 읽히도록 좀 더 어둡게 */
  .mw-bar { background: rgba(8, 8, 14, 0.55); backdrop-filter: blur(4px); }
  .mw-input-wrap { background: rgba(8, 8, 14, 0.55); backdrop-filter: blur(4px); }

  /* 말풍선 대비 강화 */
  .mw-bubble.character { background: rgba(12, 12, 18, 0.9); }

  .mw-row { max-width: 94%; }
}
</style>
