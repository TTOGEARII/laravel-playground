/**
 * MyWifeBot 채팅 API (axios)
 * POST /api/my-wife-bot/chat/init     → session_id, initial_messages, affinity
 * POST /api/my-wife-bot/chat/send     → message{role,text,narration}, affinity
 * POST /api/my-wife-bot/chat/suggest  → suggestions[]
 * POST /api/my-wife-bot/chat/narrate  → narration
 */
import axios from 'axios';

const BASE = '/api/my-wife-bot';

// 세션 인증(web 그룹) 라우트라 CSRF 토큰 + 쿠키 동봉이 필요하다.
function csrfToken() {
  const el = document.querySelector('meta[name="csrf-token"]');
  return el ? el.getAttribute('content') : '';
}

const jsonHeaders = {
  Accept: 'application/json',
  'Content-Type': 'application/json',
  'X-Requested-With': 'XMLHttpRequest',
  'X-CSRF-TOKEN': csrfToken(),
};

const requestConfig = { headers: jsonHeaders, withCredentials: true };

// SSE 프레임("event: X\ndata: {...}") 한 블록을 파싱. data 는 JSON.
function parseSseEvent(raw) {
  let event = 'message';
  const dataLines = [];
  for (const line of raw.split('\n')) {
    if (line.startsWith('event:')) event = line.slice(6).trim();
    else if (line.startsWith('data:')) dataLines.push(line.slice(5).replace(/^ /, ''));
  }
  if (!dataLines.length) return null;
  let data = {};
  try {
    data = JSON.parse(dataLines.join('\n'));
  } catch (_) {
    data = {};
  }
  return { event, data };
}

export const myWifeBotChatApi = {
  /**
   * 채팅 진입: 세션 생성 + 인트로 생성 후 반환
   * @param {string} characterId
   * @returns {Promise<{ session_id: string, initial_messages: Array<{ role: string, text: string, narration: ?string }>, affinity: number }>}
   */
  async initChat(characterId, sessionId = null) {
    const payload = { character_id: String(characterId) };
    if (sessionId) payload.session_id = String(sessionId);
    const { data } = await axios.post(`${BASE}/chat/init`, payload, requestConfig);
    return data.data;
  },

  /**
   * 메시지 전송 → Gemini 응답(지문/대사/호감도) 반환
   * @param {string} sessionId
   * @param {string} content
   * @returns {Promise<{ message: { role: string, text: string, narration: ?string }, affinity: ?number }>}
   */
  async sendMessage(sessionId, content) {
    const { data } = await axios.post(
      `${BASE}/chat/send`,
      { session_id: String(sessionId), content: String(content).trim() },
      requestConfig
    );
    return data.data;
  },

  /**
   * 메시지 전송 → Gemini 응답을 SSE 로 스트리밍 수신(토큰 단위).
   * @param {string} sessionId
   * @param {string} content
   * @param {{ onDelta?: (t:string)=>void, onDone?: (d:{message:string,narration:?string,affinity:?number})=>void, onError?: (d:object)=>void }} handlers
   */
  async sendMessageStream(sessionId, content, { onDelta, onDone, onError } = {}) {
    const res = await fetch(`${BASE}/chat/send-stream`, {
      method: 'POST',
      credentials: 'include',
      headers: { ...jsonHeaders, Accept: 'text/event-stream' },
      body: JSON.stringify({ session_id: String(sessionId), content: String(content).trim() }),
    });

    if (!res.ok || !res.body) {
      throw new Error(`stream failed: ${res.status}`);
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    // 응답 바디를 스트림으로 읽어 빈 줄(\n\n)로 구분되는 SSE 블록 단위로 파싱한다.
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      let sep;
      while ((sep = buffer.indexOf('\n\n')) !== -1) {
        const raw = buffer.slice(0, sep);
        buffer = buffer.slice(sep + 2);
        const evt = parseSseEvent(raw);
        if (!evt) continue;
        if (evt.event === 'delta') onDelta?.(evt.data.text || '');
        else if (evt.event === 'done') onDone?.(evt.data);
        else if (evt.event === 'error') onError?.(evt.data);
      }
    }
  },

  /**
   * 유저 추천 답변 요청
   * @param {string} sessionId
   * @returns {Promise<string[]>}
   */
  async suggestReplies(sessionId) {
    const { data } = await axios.post(
      `${BASE}/chat/suggest`,
      { session_id: String(sessionId) },
      requestConfig
    );
    return Array.isArray(data?.data?.suggestions) ? data.data.suggestions : [];
  },

  /**
   * 상황 묘사(지문) 생성 요청
   * @param {string} sessionId
   * @returns {Promise<string>}
   */
  async narrate(sessionId) {
    const { data } = await axios.post(
      `${BASE}/chat/narrate`,
      { session_id: String(sessionId) },
      requestConfig
    );
    return typeof data?.data?.narration === 'string' ? data.data.narration : '';
  },
};
