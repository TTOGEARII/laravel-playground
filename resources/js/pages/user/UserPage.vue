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
        <button type="button" class="user-logout" @click="logout" :disabled="loading">
          {{ loading ? '처리 중...' : '로그아웃' }}
        </button>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import authAxios from '../auth/axios.js';

const props = defineProps({
  userData: { type: Object, default: () => ({}) },
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

onMounted(() => {
  if (!user.value || !user.value.id) {
    user.value = props.userData;
  }
});
</script>
