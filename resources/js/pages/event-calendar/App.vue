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

    <!-- 월 캘린더 그리드 -->
    <div class="ec-calendar" :class="{ 'is-loading': loading }">
      <div class="ec-dow" v-for="(d, i) in ['일', '월', '화', '수', '목', '금', '토']" :key="d"
        :class="{ 'is-sun': i === 0, 'is-sat': i === 6 }">{{ d }}</div>
      <div v-for="(cell, ci) in cells" :key="ci" class="ec-day" :class="{
        'is-other': !cell.inMonth,
        'is-today': cell.isToday,
        'is-expanded': expandedDay === cell.dateStr,
      }">
        <span class="ec-day-num" :class="{ 'is-sun': cell.dow === 0, 'is-sat': cell.dow === 6 }">{{ cell.day }}</span>
        <div class="ec-day-events" v-if="cell.events.length">
          <button v-for="ev in visibleEvents(cell)" :key="ev.id" class="ec-pill" :class="`ec-pill--${ev.kind}`"
            :title="ev.title" @click="openDetail(ev.id)">
            <span class="ec-pill-text">{{ ev.title }}</span>
          </button>
          <button v-if="cell.events.length > MAX_PILLS && expandedDay !== cell.dateStr" class="ec-more"
            @click="expandedDay = cell.dateStr">+{{ cell.events.length - MAX_PILLS }}개 더</button>
        </div>
      </div>
    </div>

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
const MAX_PILLS = 3;

const now = new Date();
const year = ref(now.getFullYear());
const month = ref(now.getMonth() + 1);
const tab = ref('recommended');
const events = ref([]);
const upcoming = ref([]);
const detail = ref(null);
const loading = ref(false);
const expandedDay = ref(null);
const copied = ref(false);

function filterParams() {
  if (tab.value === 'recommended') return { jpopOnly: true };
  if (tab.value === 'concert') return { kind: 'concert' };
  return { kind: 'events' };
}

async function loadMonth() {
  loading.value = true;
  try {
    events.value = await eventCalendarApi.getMonth(year.value, month.value, filterParams());
  } catch (e) {
    console.error('month', e);
    events.value = [];
  } finally {
    loading.value = false;
  }
}

async function loadUpcoming() {
  try {
    upcoming.value = await eventCalendarApi.getUpcoming(filterParams());
  } catch (e) {
    console.error('upcoming', e);
    upcoming.value = [];
  }
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

function visibleEvents(cell) {
  return expandedDay.value === cell.dateStr ? cell.events : cell.events.slice(0, MAX_PILLS);
}

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
  expandedDay.value = null;
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
