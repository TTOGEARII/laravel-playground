<template>
  <div class="sga">
    <p v-if="!enabled" class="sga-disabled">지금은 AI 기능이 꺼져 있어요(서버 설정 필요). 잠시 후 다시 들러주세요.</p>

    <!-- 페르소나 선택 — 챗봇 캐릭터창처럼 카드로 고른다(모든 사용자의 챗봇 캐릭터) -->
    <div v-else-if="view === 'select'" class="sga-select">
      <h2 class="sga-select-title">누구와 대화할까요?</h2>
      <p class="sga-select-lead">챗봇 캐릭터를 고르면 그 캐릭터의 말투로 서브컬쳐 게임 정보를 알려드려요.</p>
      <section v-if="personaOptions.length" class="sga-persona-grid">
        <article
          v-for="p in personaOptions"
          :key="`${p.kind}-${p.ref}`"
          class="sga-persona-card"
          :class="{ 'is-current': persona && persona.kind === p.kind && persona.ref === p.ref }"
        >
          <div class="sga-persona-image">
            <img v-if="p.image" :src="p.image" :alt="p.name" loading="lazy" />
            <span v-else class="sga-persona-emoji">{{ p.emoji }}</span>
          </div>
          <h3 class="sga-persona-name">
            {{ p.name }}
            <span v-if="p.is_mine" class="sga-persona-kind">내 챗봇</span>
          </h3>
          <p class="sga-persona-desc">{{ p.description || '서브컬쳐 게임 정보를 알려드려요.' }}</p>
          <button type="button" class="sga-persona-start" @click="selectPersona(p)">대화하기</button>
        </article>
      </section>
      <p v-else class="sga-select-hint">
        아직 챗봇 캐릭터가 없어요. <a href="/my-wife-bot/characters">챗봇 만들기</a>에서 첫 캐릭터를 만들어 보세요.
      </p>
      <p v-if="personaOptions.length && !loggedIn" class="sga-select-hint">
        로그인하면 내가 만든 챗봇에 '내 챗봇' 표시가 붙고, 대화 기록도 계정에 저장돼요.
      </p>
    </div>

    <!-- 채팅 뷰 -->
    <template v-else>
      <div class="sga-topbar">
        <button type="button" class="sga-current" :disabled="streaming" title="페르소나 변경" @click="view = 'select'">
          <span class="sga-current-face">
            <img v-if="currentPersona?.image" :src="currentPersona.image" :alt="currentPersona.name" />
            <template v-else>{{ personaEmoji }}</template>
          </span>
          {{ currentPersona?.name ?? '페르소나' }}
          <small>변경 ›</small>
        </button>
        <button v-if="messages.length" type="button" class="sga-new" :disabled="streaming" @click="newChat(); view = 'select';">
          ＋ 새 대화
        </button>
      </div>

      <!-- SGI 화면에서 넘어온 게임 컨텍스트 — 게임 미명시 질문의 기준 게임 -->
      <div v-if="gameContext" class="sga-context">
        🎮 <strong>{{ games[gameContext] ?? gameContext }}</strong> 기준으로 답해요
        <button type="button" class="sga-context-off" title="게임 컨텍스트 해제" @click="clearGameContext">✕</button>
      </div>

      <!-- 빈 상태: 예시 프롬프트 -->
      <div v-if="messages.length === 0" class="sga-empty">
        <div class="sga-empty-icon">{{ personaEmoji }}</div>
        <h2>무엇이 궁금하세요?</h2>
        <p>서브컬쳐 게임의 리딤코드·레이드 편성·캐릭터·공략을 알려드려요.</p>
        <div class="sga-suggestions">
          <button v-for="s in suggestions" :key="s" type="button" class="sga-suggestion" @click="send(s)">{{ s }}</button>
        </div>
      </div>

      <!-- 대화 -->
      <div ref="scroller" class="sga-messages">
      <div v-for="(m, i) in messages" :key="i" class="sga-msg" :class="`is-${m.role}`">
        <div v-if="m.role === 'assistant'" class="sga-avatar">
          <img v-if="currentPersona?.image" :src="currentPersona.image" :alt="currentPersona?.name" />
          <template v-else>{{ personaEmoji }}</template>
        </div>
        <div class="sga-msg-body">
          <!-- 툴 진행 칩 -->
          <div v-if="m.tools?.length" class="sga-tools">
            <span v-for="(t, ti) in m.tools" :key="ti" class="sga-tool" :class="{ 'is-live': m.streaming && ti === m.tools.length - 1 }">{{ t }}</span>
          </div>
          <div v-if="m.role === 'assistant'" class="sga-bubble sga-markdown" v-html="render(m.content)" />
          <div v-else class="sga-bubble">{{ m.content }}</div>
          <span v-if="m.streaming && !m.content" class="sga-typing"><i /><i /><i /></span>
          <AgentCards v-if="m.cards?.length" :cards="m.cards" />
        </div>
      </div>
    </div>

      <!-- 입력 바 (Enter=전송 / Shift+Enter=줄바꿈) -->
      <form class="sga-inputbar" @submit.prevent="send()">
        <textarea
          ref="inputEl"
          v-model="input"
          class="sga-input"
          rows="1"
          placeholder="예) 블루아카이브 리딤코드 알려줘 (Shift+Enter 줄바꿈)"
          maxlength="2000"
          :disabled="streaming"
          @keydown.enter.exact.prevent="send()"
          @input="autoGrow"
        />
        <button type="submit" class="sga-send" :disabled="streaming || !input.trim()" aria-label="전송">
          <span v-if="streaming" class="sga-send-dots"><i /><i /><i /></span>
          <svg v-else viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
            <path d="M12 19V5M6 11l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </form>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import MarkdownIt from 'markdown-it';
import AgentCards from './AgentCards.vue';

const props = defineProps({
  enabled: { type: Boolean, default: false },
  loggedIn: { type: Boolean, default: false },
  games: { type: Object, default: () => ({}) }, // 슬러그 → 표시명 (컨텍스트 칩 라벨)
});

const md = new MarkdownIt({ html: false, linkify: true, breaks: true });

const SESSION_KEY = 'sga:session-uuid';
const PERSONA_KEY = 'sga:persona';

const messages = ref([]); // {role, content, cards, tools, streaming}
const input = ref('');
const inputEl = ref(null);

// textarea 높이 자동 확장 — Claude 처럼 내용이 늘면 박스가 커지고(최대 200px) 넘으면 그때 스크롤.
const INPUT_MAX_H = 200;
function autoGrow() {
  const el = inputEl.value;
  if (!el) return;
  el.style.height = 'auto'; // 먼저 초기화해야 줄어들 때도 정확한 scrollHeight 를 잰다
  el.style.height = `${Math.min(el.scrollHeight, INPUT_MAX_H)}px`;
}
const streaming = ref(false);
const scroller = ref(null);
const personaOptions = ref([]);
const persona = ref(JSON.parse(localStorage.getItem(PERSONA_KEY) ?? 'null')); // 선택 전에는 null — 카드에서 고른다
const sessionUuid = ref(localStorage.getItem(SESSION_KEY));

// SGI 화면 → ?game= 딥링크로 넘어온 게임 컨텍스트 (알려진 슬러그만 인정)
const urlGame = new URLSearchParams(location.search).get('game');
const gameContext = ref(urlGame && Object.hasOwn(props.games, urlGame) ? urlGame : null);

function clearGameContext() {
  gameContext.value = null;
  history.replaceState(null, '', location.pathname); // 새로고침 시 다시 붙지 않게 URL 정리
}

const suggestions = [
  '블루아카이브 리딤코드 알려줘',
  '트릭컬 지금 레이드 편성 추천해줘',
  '블아 이벤트 챌린지 공략 알려줘',
  '니케 진행 중인 레이드 뭐야?',
];

// 뷰: select(페르소나 카드 선택) ↔ chat(대화). 복원된 세션이 있으면 chat 으로 시작.
const view = ref('select');

const currentPersona = computed(() => (persona.value
  ? personaOptions.value.find((p) => p.kind === persona.value.kind && p.ref === persona.value.ref) ?? null
  : null));
const personaEmoji = computed(() => currentPersona.value?.emoji ?? '🤖');

function render(text) {
  return md.render(text ?? '');
}

function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function scrollDown() {
  await nextTick();
  scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' });
}

/** 카드에서 페르소나 선택 → 새 대화로 채팅 시작(세션의 페르소나는 생성 시 고정). */
function selectPersona(p) {
  persona.value = { kind: p.kind, ref: p.ref };
  localStorage.setItem(PERSONA_KEY, JSON.stringify(persona.value));
  newChat();
  view.value = 'chat';
}

function newChat() {
  messages.value = [];
  sessionUuid.value = null;
  localStorage.removeItem(SESSION_KEY);
}

async function send(preset) {
  const text = (preset ?? input.value).trim();
  if (!text || streaming.value) return;
  input.value = '';
  nextTick(autoGrow); // 전송 후 textarea 높이 원상복귀
  streaming.value = true;

  messages.value.push({ role: 'user', content: text });
  const reply = { role: 'assistant', content: '', cards: [], tools: [], streaming: true };
  messages.value.push(reply);
  scrollDown();

  try {
    const res = await fetch('/subculture-agent/chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        'X-CSRF-TOKEN': csrf(),
      },
      body: JSON.stringify({
        message: text,
        session_uuid: sessionUuid.value,
        persona_kind: persona.value?.kind ?? 'character',
        persona_ref: persona.value?.ref ?? null,
        game: gameContext.value,
      }),
    });
    if (!res.ok || !res.body) throw new Error(`HTTP ${res.status}`);

    // SSE 파서: "event: X\ndata: {...}\n\n" 블록 단위
    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let idx;
      while ((idx = buffer.indexOf('\n\n')) !== -1) {
        handleEvent(buffer.slice(0, idx), reply);
        buffer = buffer.slice(idx + 2);
      }
    }
  } catch (e) {
    console.error('에이전트 응답 실패', e);
    if (!reply.content) reply.content = '앗, 연결에 문제가 생겼어요. 잠시 후 다시 시도해 주세요.';
  } finally {
    reply.streaming = false;
    streaming.value = false;
    scrollDown();
  }
}

function handleEvent(block, reply) {
  const eventMatch = block.match(/^event: (.+)$/m);
  const dataMatch = block.match(/^data: (.+)$/m);
  if (!eventMatch || !dataMatch) return;
  let data;
  try { data = JSON.parse(dataMatch[1]); } catch { return; }

  switch (eventMatch[1]) {
    case 'meta':
      sessionUuid.value = data.session_uuid;
      localStorage.setItem(SESSION_KEY, data.session_uuid);
      break;
    case 'tool':
      reply.tools.push(data.label);
      scrollDown();
      break;
    case 'delta':
      reply.content += data.text;
      scrollDown();
      break;
    case 'done':
      reply.cards = data.cards ?? [];
      break;
  }
}

async function restoreSession() {
  if (!sessionUuid.value) return;
  try {
    const res = await fetch(`/subculture-agent/sessions/${sessionUuid.value}/messages`, {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error();
    const { data } = await res.json();
    persona.value = { kind: data.session.persona_kind, ref: data.session.persona_ref ?? 'guide' };
    messages.value = data.messages.map((m) => ({
      role: m.role,
      content: m.content ?? '',
      cards: m.cards ?? [],
      tools: (m.tool_calls ?? []).map((t) => t.label),
    }));
    if (messages.value.length) view.value = 'chat'; // 이어하던 대화가 있으면 바로 채팅으로
    scrollDown();
  } catch {
    newChat(); // 만료·권한 없음 등 — 조용히 페르소나 선택으로
  }
}

onMounted(async () => {
  try {
    const res = await fetch('/subculture-agent/personas', { headers: { Accept: 'application/json' } });
    const { data } = await res.json();
    personaOptions.value = data.characters ?? []; // 페르소나 = 챗봇 캐릭터 전체(프리셋 제거)
  } catch { /* 프리셋 없이도 대화는 가능 */ }
  restoreSession();
});
</script>
