<template>
  <div class="ec-app">
    <!-- 필터 탭 + 월 이동 -->
    <div class="ec-toolbar">
      <div class="ec-tabs" role="tablist">
        <button v-for="t in TABS" :key="t.key" class="ec-tab" :class="{ 'is-active': tab === t.key }"
          role="tab" :aria-selected="tab === t.key" @click="tab = t.key">{{ t.label }}</button>
      </div>
      <div class="ec-month-nav">
        <button class="ec-nav-btn" aria-label="이전 달" @click="moveMonth(-1)">‹</button>
        <span class="ec-month-label">{{ year }}년 {{ month }}월</span>
        <button class="ec-nav-btn" aria-label="다음 달" @click="moveMonth(1)">›</button>
        <button class="ec-today-btn" @click="goToday">오늘</button>
      </div>
    </div>

    <!-- 월 캘린더 — 주 단위 레인에 연속 띠(밴드)로 렌더(구글 캘린더식). 모바일은 점(dot) 유지 -->
    <div class="ec-calendar" :class="{ 'is-loading': loading }">
      <div class="ec-dow-row">
        <div class="ec-dow" v-for="(d, i) in ['일', '월', '화', '수', '목', '금', '토']" :key="d"
          :class="{ 'is-sun': i === 0, 'is-sat': i === 6 }">{{ d }}</div>
      </div>
      <div v-for="(week, wi) in weeks" :key="wi" class="ec-week" :style="{ '--lanes': week.laneCount }">
        <div v-for="cell in week.cells" :key="cell.dateStr" class="ec-day" :class="{
          'is-other': !cell.inMonth,
          'is-today': cell.isToday,
          'is-selected': selectedDay === cell.dateStr,
          'has-events': cell.events.length > 0,
        }" @click="selectDay(cell)">
          <span class="ec-day-num" :class="{ 'is-sun': cell.dow === 0, 'is-sat': cell.dow === 6 }">{{ cell.day }}</span>
          <div class="ec-day-dots" v-if="cell.events.length">
            <i v-for="ev in cell.events.slice(0, 4)" :key="'d' + (ev.__ticket ? 't' : '') + ev.id" class="ec-dot"
              :class="ev.__ticket ? 'ec-dot--ticket' : `ec-dot--${ev.kind}`" />
          </div>
        </div>
        <!-- 띠 오버레이: 행사 기간이 이어지면 셀 경계를 넘어 하나의 띠로 -->
        <div class="ec-bands">
          <button v-for="seg in week.segments" :key="seg.key" class="ec-band"
            :class="[seg.ev.__ticket ? 'ec-band--ticket' : `ec-band--${seg.ev.kind}`, {
              'is-cut-left': seg.cutLeft, 'is-cut-right': seg.cutRight,
            }]"
            :style="{ left: `calc(${(seg.startCol / 7) * 100}% + 2px)`, width: `calc(${(seg.span / 7) * 100}% - 4px)`, top: `calc(26px + ${seg.lane} * var(--ec-lane-h))` }"
            :title="seg.ev.__ticket ? `티켓 오픈: ${seg.ev.title}` : seg.ev.title"
            @click.stop="openDetail(seg.ev.id)">
            <span class="ec-band-text">{{ seg.ev.__ticket ? '🎫 오픈 ' + seg.ev.title : seg.ev.title }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- 선택한 날짜의 행사(모바일 주 동선 — 날짜 탭 → 리스트) -->
    <section v-if="selectedDayEvents.length" class="ec-day-panel">
      <h2 class="ec-section-title">{{ selectedDayLabel }}</h2>
      <ul class="ec-upcoming-list">
        <li v-for="ev in selectedDayEvents" :key="'s' + ev.id">
          <button class="ec-upcoming-item" @click="openDetail(ev.id)">
            <span class="ec-up-body">
              <span class="ec-up-title">{{ ev.title }}</span>
              <span class="ec-up-meta">
                <span class="ec-badge" :class="`ec-badge--${ev.kind}`">{{ ev.kind_label }}</span>
                <span v-if="ev.genre === 'jpop'" class="ec-badge ec-badge--jpop">J-POP</span>
                <span v-if="ev.venue" class="ec-up-venue">{{ ev.venue }}</span>
              </span>
            </span>
            <img v-if="ev.poster_url" class="ec-up-poster" :src="ev.poster_url" :alt="ev.title" loading="lazy" />
          </button>
        </li>
      </ul>
    </section>

    <!-- 티켓 오픈 예정 — 예매일이 공연일보다 중요(임박순 + D-day) -->
    <section v-if="ticketOpens.length" class="ec-upcoming ec-ticket-opens">
      <h2 class="ec-section-title">🎫 티켓 오픈 예정</h2>
      <ul class="ec-upcoming-list">
        <li v-for="ev in ticketOpens" :key="'to' + ev.id">
          <button class="ec-upcoming-item" @click="openDetail(ev.id)">
            <span class="ec-open-dday" :class="{ 'is-today': ddayLabel(ev.ticket_opens_on).includes('오늘') }">
              {{ ddayLabel(ev.ticket_opens_on) }}
            </span>
            <span class="ec-up-body">
              <span class="ec-up-title">{{ ev.title }}</span>
              <span class="ec-up-meta">
                <span class="ec-open-when">{{ ev.ticket_open_text || formatMd(ev.ticket_opens_on) + ' 오픈' }}</span>
                <span v-if="ev.genre === 'jpop'" class="ec-badge ec-badge--jpop">J-POP</span>
              </span>
            </span>
            <span class="ec-open-perf">공연 {{ formatMd(ev.starts_on) }}</span>
          </button>
        </li>
      </ul>
    </section>

    <!-- 다가오는 행사 -->
    <section class="ec-upcoming">
      <h2 class="ec-section-title">다가오는 행사</h2>
      <p v-if="!upcoming.length && !loading" class="ec-empty">예정된 행사가 없습니다.</p>
      <ul class="ec-upcoming-list">
        <li v-for="ev in upcoming" :key="ev.id">
          <button class="ec-upcoming-item" @click="openDetail(ev.id)">
            <span class="ec-up-date">
              <b>{{ formatMd(ev.starts_on) }}</b>
              <small v-if="ev.ends_on">~{{ formatMd(ev.ends_on) }}</small>
            </span>
            <span class="ec-up-body">
              <span class="ec-up-title">{{ ev.title }}</span>
              <span class="ec-up-meta">
                <span class="ec-badge" :class="`ec-badge--${ev.kind}`">{{ ev.kind_label }}</span>
                <span v-if="ev.genre === 'jpop'" class="ec-badge ec-badge--jpop">J-POP</span>
                <span v-if="ev.venue" class="ec-up-venue">{{ ev.venue }}</span>
              </span>
            </span>
            <img v-if="ev.poster_url" class="ec-up-poster" :src="ev.poster_url" :alt="ev.title" loading="lazy" />
          </button>
        </li>
      </ul>
    </section>

    <!-- 상세 오버레이 -->
    <div v-if="detail" class="ec-overlay" @click.self="closeDetail">
      <div class="ec-detail" role="dialog" aria-modal="true">
        <button class="ec-close" aria-label="닫기" @click="closeDetail">✕</button>
        <div class="ec-detail-grid">
          <div v-if="detail.poster_url" class="ec-detail-poster">
            <img :src="detail.poster_url" :alt="detail.title" />
          </div>
          <div class="ec-detail-body">
            <div class="ec-detail-badges">
              <span class="ec-badge" :class="`ec-badge--${detail.kind}`">{{ detail.kind_label }}</span>
              <span v-if="detail.genre === 'jpop'" class="ec-badge ec-badge--jpop">J-POP</span>
            </div>
            <h2 class="ec-detail-title">{{ detail.title }}</h2>
            <dl class="ec-detail-info">
              <div><dt>일시</dt><dd>{{ formatRange(detail) }}<template v-if="detail.time_text"> · {{ detail.time_text }}</template></dd></div>
              <div v-if="detail.venue"><dt>장소</dt><dd>{{ detail.venue }}</dd></div>
              <div v-if="detail.price_text"><dt>가격</dt><dd>{{ detail.price_text }}</dd></div>
              <div v-if="detail.ticket_open_text"><dt>티켓 오픈</dt><dd>{{ detail.ticket_open_text }}</dd></div>
              <div v-if="detail.booking_text"><dt>예매처</dt><dd>{{ detail.booking_text }}</dd></div>
            </dl>
            <div class="ec-detail-actions">
              <a v-for="link in detail.ticket_links || []" :key="link.url" class="ec-btn ec-btn--primary"
                :href="link.url" target="_blank" rel="noopener">{{ link.label || '티켓 구매' }}</a>
              <a v-if="detail.detail_url" class="ec-btn" :href="detail.detail_url" target="_blank" rel="noopener">원문 보기</a>
              <button class="ec-btn" @click="copyLink">링크 복사</button>
            </div>
            <p v-if="copied" class="ec-copied">링크가 복사되었습니다.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { eventCalendarApi } from './api';

const props = defineProps({
  initialEventId: { type: Number, default: null },
});

const TABS = [
  { key: 'recommended', label: '추천 (J-pop·행사)' },
  { key: 'concert', label: '공연 전체' },
  { key: 'events', label: '동인·행사' },
];

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);
const tab = ref('recommended');
const events = ref([]);
const monthTicketOpens = ref([]);
const upcoming = ref([]);
const ticketOpens = ref([]);
const detail = ref(null);
const loading = ref(false);
const selectedDay = ref(null);
const copied = ref(false);

function filterParams() {
  if (tab.value === 'recommended') return { jpopOnly: true };
  if (tab.value === 'concert') return { kind: 'concert' };
  return { kind: 'events' };
}

async function loadMonth() {
  loading.value = true;
  try {
    const res = await eventCalendarApi.getMonth(year.value, month.value, filterParams());
    events.value = res.events;
    monthTicketOpens.value = res.ticketOpens;
  } catch (e) {
    console.error('month', e);
    events.value = [];
    monthTicketOpens.value = [];
  } finally {
    loading.value = false;
  }
}

async function loadUpcoming() {
  try {
    upcoming.value = await eventCalendarApi.getUpcoming(filterParams());
    ticketOpens.value = await eventCalendarApi.getTicketOpens(filterParams());
  } catch (e) {
    console.error('upcoming', e);
    upcoming.value = [];
    ticketOpens.value = [];
  }
}

/** 티켓 오픈 D-day 라벨(오늘/내일/D-n). */
function ddayLabel(dateStr) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.round((new Date(dateStr + 'T00:00:00') - today) / 86400000);
  return diff <= 0 ? '오늘 오픈' : diff === 1 ? '내일 오픈' : `D-${diff}`;
}

// 월 그리드 셀(앞뒤 이웃달 포함 7×N)
const cells = computed(() => {
  const y = year.value;
  const m = month.value;
  const first = new Date(y, m - 1, 1);
  const start = new Date(first);
  start.setDate(1 - first.getDay());
  const todayStr = toDateStr(new Date());

  const byDay = {};
  for (const ev of events.value) {
    const s = ev.starts_on;
    const e = ev.ends_on || ev.starts_on;
    for (let d = new Date(s + 'T00:00:00'); toDateStr(d) <= e; d.setDate(d.getDate() + 1)) {
      (byDay[toDateStr(d)] ??= []).push(ev);
    }
  }
  // 티켓 오픈일 마커(🎫) — 공연일과 별개로 '오픈되는 날'에 표시(예매일 중심 UX)
  for (const ev of monthTicketOpens.value) {
    if (ev.ticket_opens_on) {
      (byDay[ev.ticket_opens_on] ??= []).push({ ...ev, __ticket: true });
    }
  }

  const out = [];
  const cur = new Date(start);
  do {
    const dateStr = toDateStr(cur);
    out.push({
      day: cur.getDate(),
      dow: cur.getDay(),
      dateStr,
      inMonth: cur.getMonth() === m - 1,
      isToday: dateStr === todayStr,
      events: byDay[dateStr] || [],
    });
    cur.setDate(cur.getDate() + 1);
  } while (cur.getMonth() === m - 1 || out.length % 7 !== 0);

  return out;
});

// 주 단위 밴드 배치: 행사 기간을 주별 구간으로 자르고, 겹치지 않는 레인에 그리디 배치.
// 주를 넘는 행사는 cutLeft/cutRight 로 모서리를 이어 붙여(라운딩 제거) 띠가 이어져 보이게 한다.
const weeks = computed(() => {
  const flat = cells.value;
  const out = [];
  for (let i = 0; i < flat.length; i += 7) {
    const wcells = flat.slice(i, i + 7);
    const weekStart = wcells[0].dateStr;
    const weekEnd = wcells[6].dateStr;

    const segs = [];
    for (const ev of events.value) {
      const s = ev.starts_on;
      const e = ev.ends_on || ev.starts_on;
      if (e < weekStart || s > weekEnd) continue;
      const startCol = wcells.findIndex((c) => c.dateStr === (s < weekStart ? weekStart : s));
      const endCol = wcells.findIndex((c) => c.dateStr === (e > weekEnd ? weekEnd : e));
      segs.push({
        ev, startCol, span: endCol - startCol + 1,
        cutLeft: s < weekStart, cutRight: e > weekEnd,
        key: ev.id + '-' + weekStart,
      });
    }
    for (const ev of monthTicketOpens.value) {
      const d = ev.ticket_opens_on;
      if (!d || d < weekStart || d > weekEnd) continue;
      const col = wcells.findIndex((c) => c.dateStr === d);
      segs.push({ ev: { ...ev, __ticket: true }, startCol: col, span: 1, cutLeft: false, cutRight: false, key: 't' + ev.id + '-' + weekStart });
    }

    // 레인 배치(시작 열 → 긴 것 우선) — 같은 레인에서 구간이 겹치면 다음 레인으로
    segs.sort((a, b) => a.startCol - b.startCol || b.span - a.span);
    const lanes = [];
    for (const seg of segs) {
      let lane = 0;
      const overlaps = (o) => !(seg.startCol + seg.span - 1 < o.startCol || seg.startCol > o.startCol + o.span - 1);
      while ((lanes[lane] || []).some(overlaps)) lane++;
      (lanes[lane] ??= []).push(seg);
      seg.lane = lane;
    }
    out.push({ cells: wcells, segments: segs, laneCount: Math.max(lanes.length, 1) });
  }
  return out;
});

// 날짜 탭(모바일 주 동선): 행사 있는 날을 선택하면 그리드 아래에 그날 행사 리스트 표시
function selectDay(cell) {
  selectedDay.value = cell.events.length && selectedDay.value !== cell.dateStr ? cell.dateStr : null;
}
const selectedDayEvents = computed(() => {
  if (!selectedDay.value) return [];
  return cells.value.find((c) => c.dateStr === selectedDay.value)?.events || [];
});
const selectedDayLabel = computed(() => {
  if (!selectedDay.value) return '';
  const [y, m, d] = selectedDay.value.split('-').map(Number);
  return `${m}월 ${d}일 행사`;
});

function toDateStr(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function formatMd(s) {
  const [, m, d] = s.split('-');
  return `${Number(m)}/${Number(d)}`;
}
function formatRange(ev) {
  const fmt = (s) => {
    const [y, m, d] = s.split('-');
    return `${y}.${m}.${d}`;
  };
  return ev.ends_on ? `${fmt(ev.starts_on)} ~ ${fmt(ev.ends_on)}` : fmt(ev.starts_on);
}

function moveMonth(delta) {
  const d = new Date(year.value, month.value - 1 + delta, 1);
  year.value = d.getFullYear();
  month.value = d.getMonth() + 1;
  selectedDay.value = null;
}
function goToday() {
  year.value = now.getFullYear();
  month.value = now.getMonth() + 1;
}

async function openDetail(id, push = true) {
  try {
    detail.value = await eventCalendarApi.getEvent(id);
    copied.value = false;
    if (push) history.pushState({ ecEvent: id }, '', `/event-calendar/${id}`);
    // 상세가 열린 달로 캘린더 이동(딥링크 진입 대응)
    const [y, m] = detail.value.starts_on.split('-').map(Number);
    if (y !== year.value || m !== month.value) {
      year.value = y;
      month.value = m;
    }
  } catch (e) {
    console.error('detail', e);
  }
}
function closeDetail(push = true) {
  detail.value = null;
  if (push !== false) history.pushState({}, '', '/event-calendar');
}
function onPopState(e) {
  const m = location.pathname.match(/\/event-calendar\/(\d+)/);
  if (m) openDetail(Number(m[1]), false);
  else detail.value = null;
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(`${location.origin}/event-calendar/${detail.value.id}`);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
  } catch (e) { /* 클립보드 미지원 무시 */ }
}

watch([year, month, tab], () => loadMonth());
watch(tab, () => loadUpcoming());

onMounted(() => {
  window.addEventListener('popstate', onPopState);
  loadMonth();
  loadUpcoming();
  if (props.initialEventId) openDetail(props.initialEventId, false);
});
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));
</script>
