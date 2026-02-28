<template>
  <div class="chat-page">
    <header class="chat-back-header">
      <a :href="charactersUrl" class="chat-back-button">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        목록으로
      </a>
    </header>
    <main class="chat-main">
        <div class="chat-messages" ref="messagesEl">
          <div v-if="loadingIntro" class="chat-message character chat-intro-loading">
            <div class="chat-avatar">
              <img v-if="hasCharacterImage" :src="characterImageSrc" :alt="character.name" />
              <span v-else class="chat-avatar-placeholder">{{ (character?.name || '?').charAt(0) }}</span>
            </div>
            <div class="chat-bubble chat-bubble--typing">
              <span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>
            </div>
          </div>
          <div v-for="(msg, i) in messages" :key="i" class="chat-message" :class="msg.role">
            <div v-if="msg.role === 'character'" class="chat-avatar">
              <img v-if="hasCharacterImage" :src="characterImageSrc" :alt="character.name" />
              <span v-else class="chat-avatar-placeholder">{{ (character?.name || '?').charAt(0) }}</span>
            </div>
            <div class="chat-bubble">
              <p class="chat-bubble-text">{{ msg.text }}</p>
            </div>
            <div v-if="msg.role === 'user'" class="chat-avatar chat-avatar--user">
              <span>나</span>
            </div>
          </div>
          <div v-if="thinking" class="chat-message character">
            <div class="chat-avatar">
              <img v-if="hasCharacterImage" :src="characterImageSrc" :alt="character.name" />
              <span v-else class="chat-avatar-placeholder">{{ (character?.name || '?').charAt(0) }}</span>
            </div>
            <div class="chat-bubble chat-bubble--typing">
              <span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>
            </div>
          </div>
        </div>

        <div class="chat-input-wrap">
          <div class="chat-input-inner">
            <textarea
              v-model="inputText"
              placeholder="메시지를 입력하세요..."
              rows="1"
              class="chat-input"
              @keydown.enter.exact.prevent="send"
              ref="inputEl"
            />
            <button type="button" class="chat-send" @click="send" :disabled="!inputText.trim()">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
              </svg>
              전송
            </button>
          </div>
          <p class="chat-input-hint">Enter로 전송 · Shift+Enter 줄바꿈</p>
        </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted } from 'vue';
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

const messages = ref([]);
const sessionId = ref('');
const loadingIntro = ref(true);
const inputText = ref('');
const thinking = ref(false);
const messagesEl = ref(null);
const inputEl = ref(null);

async function send() {
  const text = inputText.value.trim();
  if (!text) return;
  if (!sessionId.value) {
    messages.value.push({
      role: 'character',
      text: '세션이 없어 대화를 이어갈 수 없어요. 페이지를 새로고침한 뒤 다시 말 걸어 주세요.',
    });
    nextTick(() => scrollToBottom());
    return;
  }
  messages.value.push({ role: 'user', text });
  inputText.value = '';
  nextTick(() => scrollToBottom());

  thinking.value = true;
  try {
    const msg = await myWifeBotChatApi.sendMessage(sessionId.value, text);
    messages.value.push({
      role: msg.role === 'character' ? 'character' : 'character',
      text: typeof msg.text === 'string' ? msg.text : (msg.content ?? ''),
    });
  } catch (_) {
    messages.value.push({
      role: 'character',
      text: '응답을 받지 못했어요. 잠시 후 다시 시도해 주세요.',
    });
  } finally {
    thinking.value = false;
    nextTick(() => scrollToBottom());
  }
}

function scrollToBottom() {
  if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
}

function getCharacterIdFromUrl() {
  const m = window.location.pathname.match(/\/my-wife-bot\/chat\/([^/]+)/);
  return m ? m[1] : null;
}

onMounted(async () => {
  const cid = props.character?.id ?? getCharacterIdFromUrl();
  try {
    if (cid) {
      const data = await myWifeBotChatApi.initChat(cid);
      sessionId.value = data.session_id || '';
      const list = data.initial_messages || [];
      messages.value = (list || []).map((m) => ({
        role: m.role,
        text: typeof m.text === 'string' ? m.text : (m.content ?? ''),
      }));
    }
    if (messages.value.length === 0 && props.character?.intro) {
      messages.value = [{ role: 'character', text: String(props.character.intro).trim() }];
    }
    if (messages.value.length === 0) {
      messages.value = [{ role: 'character', text: `안녕하세요, ${props.character?.name || '캐릭터'}이에요. 편하게 말 걸어 주세요.` }];
    }
  } catch (_) {
    messages.value = [{ role: 'character', text: '인트로를 불러오지 못했어요. 편하게 말 걸어 주세요.' }];
  } finally {
    loadingIntro.value = false;
  }
  nextTick(() => {
    if (messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
  });
});
</script>

<style scoped>
.chat-page {
  display: flex;
  flex-direction: column;
  min-height: 60vh;
  background: var(--bg-card);
  border: 1px solid var(--border-color);
  border-radius: 20px;
  overflow: hidden;
}
.chat-back-header {
  flex-shrink: 0;
  padding: 12px 20px;
  border-bottom: 1px solid var(--border-color);
  background: rgba(0, 0, 0, 0.15);
}
.chat-back-button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 0.875rem;
  font-weight: 500;
  transition: all 0.2s ease;
}
.chat-back-button:hover {
  background: rgba(255, 255, 255, 0.08);
  color: var(--text-primary);
}
.chat-back-button svg {
  width: 18px;
  height: 18px;
}
.chat-main { flex: 1; display: flex; flex-direction: column; min-height: 0; min-width: 0; }
.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.chat-message {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  max-width: 85%;
}
.chat-message.character { align-self: flex-start; }
.chat-message.user { align-self: flex-end; flex-direction: row-reverse; }
.chat-avatar {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  overflow: hidden;
  flex-shrink: 0;
  background: rgba(255,255,255,0.08);
  border: 1px solid var(--border-color);
}
.chat-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.chat-avatar-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: rgba(139, 92, 246, 0.25);
  border-radius: inherit;
}
.chat-avatar--user {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  color: var(--text-secondary);
}
.chat-bubble {
  padding: 12px 16px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid var(--border-color);
}
.chat-message.user .chat-bubble {
  background: rgba(139, 92, 246, 0.25);
  border-color: rgba(139, 92, 246, 0.4);
}
.chat-bubble-text { margin: 0; font-size: 0.9375rem; line-height: 1.6; color: var(--text-primary); white-space: pre-wrap; word-break: break-word; }
.chat-bubble--typing {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 16px 20px;
}
.typing-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--text-secondary);
  animation: typing 1.4s ease-in-out infinite both;
}
.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing {
  0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
  40% { transform: scale(1); opacity: 1; }
}

.chat-input-wrap { padding: 16px 20px; border-top: 1px solid var(--border-color); background: rgba(0,0,0,0.15); }
.chat-input-inner {
  display: flex;
  gap: 12px;
  align-items: flex-end;
  background: rgba(255,255,255,0.05);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 10px 14px;
}
.chat-input {
  flex: 1;
  min-height: 24px;
  max-height: 120px;
  background: none;
  border: none;
  color: var(--text-primary);
  font-size: 0.9375rem;
  font-family: inherit;
  resize: none;
  outline: none;
}
.chat-input::placeholder { color: var(--text-secondary); opacity: 0.8; }
.chat-send {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: linear-gradient(135deg, var(--accent-2), rgba(139, 92, 246, 0.8));
  border: none;
  border-radius: 10px;
  color: #fff;
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  transition: opacity 0.2s;
}
.chat-send:hover:not(:disabled) { opacity: 0.95; }
.chat-send:disabled { opacity: 0.5; cursor: not-allowed; }
.chat-input-hint { margin: 8px 0 0 0; font-size: 0.75rem; color: var(--text-secondary); }

@media (max-width: 767px) {
  .chat-message { max-width: 95%; }
}
</style>
