/**
 * EventCalendar API (BASE = /api/event-calendar)
 * GET /events            ?year&month | ?upcoming=1&limit — 공통 kind/jpop_only
 * GET /events/{id}       상세
 */
import axios from 'axios';

const BASE = '/api/event-calendar';

export const eventCalendarApi = {
  /** 월 행사 목록 + 이 달 티켓 오픈(🎫) — { events, ticketOpens } */
  async getMonth(year, month, { kind, jpopOnly } = {}) {
    const q = new URLSearchParams({ year, month });
    if (kind) q.set('kind', kind);
    if (jpopOnly) q.set('jpop_only', '1');
    const { data } = await axios.get(`${BASE}/events?${q}`);
    return { events: data.data || [], ticketOpens: data.ticket_opens || [] };
  },

  /** 다가오는 티켓 오픈(임박순) */
  async getTicketOpens({ kind, jpopOnly, limit = 10 } = {}) {
    const q = new URLSearchParams({ ticket_opens: '1', limit });
    if (kind) q.set('kind', kind);
    if (jpopOnly) q.set('jpop_only', '1');
    const { data } = await axios.get(`${BASE}/events?${q}`);
    return data.data || [];
  },

  /** 다가오는 행사 */
  async getUpcoming({ kind, jpopOnly, limit = 12 } = {}) {
    const q = new URLSearchParams({ upcoming: '1', limit });
    if (kind) q.set('kind', kind);
    if (jpopOnly) q.set('jpop_only', '1');
    const { data } = await axios.get(`${BASE}/events?${q}`);
    return data.data || [];
  },

  /** 행사 상세 */
  async getEvent(id) {
    const { data } = await axios.get(`${BASE}/events/${id}`);
    return data.data;
  },
};
