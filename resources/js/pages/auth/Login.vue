<template>
  <div class="auth-wrap">
    <a href="/" class="auth-back">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      메인으로
    </a>
    <div class="auth-box">
      <h1 class="auth-title">로그인</h1>
    <p v-if="socialError" class="auth-error auth-error--banner">{{ socialError }}</p>
    <form @submit.prevent="submit" class="auth-form">
      <div class="auth-field">
        <label for="login-email">이메일</label>
        <input
          id="login-email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          placeholder="example@email.com"
        />
      </div>
      <div class="auth-field">
        <label for="login-password">비밀번호</label>
        <input
          id="login-password"
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          placeholder="비밀번호"
        />
      </div>
      <div class="auth-field auth-field--row">
        <label class="auth-checkbox-label">
          <input v-model="remember" type="checkbox" />
          <span>로그인 유지</span>
        </label>
      </div>
      <p v-if="error" class="auth-error">{{ error }}</p>
      <button type="submit" class="auth-submit" :disabled="loading">
        {{ loading ? '로그인 중...' : '로그인' }}
      </button>
    </form>

      <div class="auth-divider"><span>또는</span></div>

      <a :href="kakaoLoginUrl" class="auth-social auth-social--kakao">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M12 3C6.99 3 3 6.2 3 10.14c0 2.52 1.68 4.73 4.2 6L6.3 19.6c-.08.3.25.53.5.36l4.02-2.66c.39.04.78.06 1.18.06 5.01 0 9-3.2 9-7.22C21 6.2 17.01 3 12 3z" />
        </svg>
        카카오로 로그인
      </a>

      <p class="auth-switch">
        계정이 없으신가요?
        <a :href="registerUrl">회원가입</a>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import authAxios from './axios.js';

const registerUrl = '/register';
const kakaoLoginUrl = '/auth/kakao/redirect';

// 소셜 로그인 실패 시 서버가 세션 플래시(social_error)를 mount div 의 data 속성으로 내려준다.
const socialError = ref(document.getElementById('auth-app')?.dataset.socialError || '');

const email = ref('');
const password = ref('');
const remember = ref(false);
const loading = ref(false);
const error = ref('');

const submit = async () => {
  error.value = '';
  loading.value = true;
  try {
    const { data } = await authAxios.post('/login', {
      email: email.value,
      password: password.value,
      remember: remember.value,
    });
    if (data.ok && data.redirect) {
      window.location.href = data.redirect;
      return;
    }
    error.value = data.message || '로그인에 실패했습니다.';
  } catch (err) {
    if (err.response?.status === 429) {
      error.value = '시도 횟수를 초과했습니다. 1분 후에 다시 시도해 주세요.';
    } else {
      const res = err.response?.data;
      error.value = res?.message || '요청 중 오류가 발생했습니다.';
    }
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
.auth-error--banner {
  margin-bottom: 16px;
  text-align: center;
}

.auth-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 20px 0;
  color: #9ca3af;
  font-size: 13px;
}
.auth-divider::before,
.auth-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #e5e7eb;
}

.auth-social {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 12px 16px;
  border-radius: 10px;
  font-weight: 700;
  font-size: 15px;
  text-decoration: none;
  transition: filter 0.15s ease;
}
.auth-social:hover {
  filter: brightness(0.96);
}
.auth-social--kakao {
  background: #fee500;
  color: #191600;
}
</style>
