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
