/**
 * MyWifeBot 채팅 API (axios)
 * POST /api/my-wife-bot/chat/init → session_id, initial_messages
 * POST /api/my-wife-bot/chat/send → message
 */
import axios from 'axios';

const BASE = '/api/my-wife-bot';

const jsonHeaders = {
  Accept: 'application/json',
  'Content-Type': 'application/json',
};

export const myWifeBotChatApi = {
  /**
   * 채팅 진입: 세션 생성 + 인트로 생성 후 반환
   * @param {string} characterId
   * @returns {Promise<{ session_id: string, initial_messages: Array<{ role: string, text: string }> }>}
   */
  async initChat(characterId) {
    const { data } = await axios.post(
      `${BASE}/chat/init`,
      { character_id: String(characterId) },
      { headers: jsonHeaders }
    );
    return data.data;
  },

  /**
   * 메시지 전송 → Gemini 응답 반환
   * @param {string} sessionId
   * @param {string} content
   * @returns {Promise<{ role: string, text: string }>}
   */
  async sendMessage(sessionId, content) {
    const { data } = await axios.post(
      `${BASE}/chat/send`,
      { session_id: String(sessionId), content: String(content).trim() },
      { headers: jsonHeaders }
    );
    return data.data.message;
  },
};
