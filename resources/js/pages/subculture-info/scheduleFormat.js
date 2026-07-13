// 정보검색 일정 모듈(배너·이벤트·미래시) 공용 날짜·상태 포맷 헬퍼

/** ISO → 'M.DD' */
export function fmtDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getMonth() + 1}.${String(d.getDate()).padStart(2, '0')}`;
}

/** 기간 'M.DD ~ M.DD' */
export function fmtRange(startIso, endIso) {
  if (!startIso && !endIso) return '';
  return `${startIso ? fmtDate(startIso) : '?'} ~ ${endIso ? fmtDate(endIso) : '?'}`;
}

/** status(active/upcoming/ended) + 날짜로 D-day 라벨 */
export function dday(item) {
  const now = Date.now();
  const days = (iso) => Math.ceil((new Date(iso).getTime() - now) / 86400000);
  if (item.status === 'upcoming' && item.starts_at) {
    const d = days(item.starts_at);
    return d <= 0 ? '곧 시작' : `시작 D-${d}`;
  }
  if (item.status === 'active' && item.ends_at) {
    const d = days(item.ends_at);
    return d <= 0 ? '종료 임박' : `D-${d}`;
  }
  return '종료';
}
