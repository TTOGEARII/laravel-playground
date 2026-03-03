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
