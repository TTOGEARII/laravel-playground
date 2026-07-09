<template>
  <div class="user-page-wrap">
    <header class="user-header">
      <a href="/" class="user-back">← 메인으로</a>
    </header>
    <main class="user-main">
      <div class="user-card">
        <h1 class="user-title">마이페이지</h1>
        <dl class="user-info">
          <dt>이름</dt>
          <dd>{{ user?.name }}</dd>
          <dt>이메일</dt>
          <dd>{{ user?.email }}</dd>
        </dl>

        <section class="user-settings">
          <h2 class="user-settings-title">설정</h2>

          <div class="user-setting-row">
            <div class="user-setting-text">
              <span class="user-setting-name">다크 모드</span>
              <span class="user-setting-desc">끄면 라이트 테마로 표시됩니다</span>
            </div>
            <button
              type="button"
              class="ds-switch"
              role="switch"
              :aria-checked="isDark ? 'true' : 'false'"
              aria-label="다크 모드"
              @click="toggleTheme"
            ></button>
          </div>

          <div class="user-setting-row">
            <div class="user-setting-text">
              <span class="user-setting-name">새 리딤코드 알림</span>
              <span class="user-setting-desc">{{ pushDesc }}</span>
            </div>
            <button
              type="button"
              class="ds-switch"
              role="switch"
              :aria-checked="pushOn ? 'true' : 'false'"
              aria-label="새 리딤코드 알림"
              :disabled="!pushAvailable || pushBusy"
              @click="togglePush"
            ></button>
          </div>
        </section>

        <button type="button" class="user-logout" @click="logout" :disabled="loading">
          {{ loading ? '처리 중...' : '로그아웃' }}
        </button>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import authAxios from '../auth/axios.js';

const props = defineProps({
  userData: { type: Object, default: () => ({}) },
  vapidKey: { type: String, default: '' },
});

const user = ref(props.userData);
const loading = ref(false);

async function logout() {
  loading.value = true;
  try {
    const { data } = await authAxios.post('/logout');
    if (data.ok && data.redirect) {
      window.location.href = data.redirect;
    }
  } finally {
    loading.value = false;
  }
}

// === 다크 모드 — 레이아웃의 window.applyTheme(localStorage 저장 포함) 사용 ===
const isDark = ref(document.documentElement.getAttribute('data-theme') !== 'light');

function toggleTheme() {
  isDark.value = !isDark.value;
  window.applyTheme?.(isDark.value ? 'dark' : 'light');
}

// === 앱 푸시(새 리딤코드 알림) — 이 브라우저(기기) 단위 구독 ===
const pushSupported =
  'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const pushAvailable = computed(() => pushSupported && !!props.vapidKey);
const pushOn = ref(false);
const pushBusy = ref(false);
const pushDesc = computed(() => {
  if (!pushSupported) return '이 브라우저는 푸시 알림을 지원하지 않습니다';
  if (!props.vapidKey) return '서버에 푸시 설정이 없어 사용할 수 없습니다';
  return '새 리딤코드가 등록되면 이 기기로 알림을 보냅니다';
});

// base64url → Uint8Array (applicationServerKey 형식)
function vapidToKey() {
  const b64 = props.vapidKey.replace(/-/g, '+').replace(/_/g, '/');
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  return Uint8Array.from(atob(b64 + pad), (c) => c.charCodeAt(0));
}

async function togglePush() {
  pushBusy.value = true;
  try {
    const reg = await navigator.serviceWorker.ready;
    const existing = await reg.pushManager.getSubscription();

    if (existing) {
      await authAxios.post('/push/unsubscribe', { endpoint: existing.endpoint });
      await existing.unsubscribe();
      pushOn.value = false;
      return;
    }

    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
      alert('알림 권한이 차단돼 있어요. 브라우저 설정에서 이 사이트의 알림을 허용해 주세요.');
      return;
    }
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: vapidToKey(),
    });
    try {
      const { keys } = sub.toJSON();
      await authAxios.post('/push/subscribe', {
        endpoint: sub.endpoint,
        keys: { p256dh: keys.p256dh, auth: keys.auth },
      });
      pushOn.value = true;
    } catch (e) {
      // 서버 등록 실패 시 브라우저 구독도 원복 — 유령 구독 방지
      await sub.unsubscribe();
      throw e;
    }
  } catch (e) {
    console.error('푸시 설정 실패', e);
  } finally {
    pushBusy.value = false;
  }
}

onMounted(async () => {
  if (!user.value || !user.value.id) {
    user.value = props.userData;
  }
  if (pushAvailable.value) {
    try {
      const reg = await navigator.serviceWorker.ready;
      pushOn.value = !!(await reg.pushManager.getSubscription());
    } catch (_) {
      /* SW 미등록 등 — 꺼짐 상태 유지 */
    }
  }
});
</script>
