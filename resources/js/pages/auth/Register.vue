<template>
  <div class="auth-wrap">
    <a href="/" class="auth-back">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      메인으로
    </a>
    <div class="auth-box">
      <h1 class="auth-title">회원가입</h1>
    <form @submit.prevent="submit" class="auth-form">
      <div class="auth-field">
        <label for="reg-name">이름</label>
        <input
          id="reg-name"
          v-model="name"
          type="text"
          required
          autocomplete="name"
          placeholder="이름"
        />
      </div>
      <div class="auth-field">
        <label for="reg-email">이메일</label>
        <input
          id="reg-email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          placeholder="example@email.com"
        />
      </div>
      <div class="auth-field">
        <label for="reg-password">비밀번호</label>
        <input
          id="reg-password"
          v-model="password"
          type="password"
          required
          autocomplete="new-password"
          placeholder="8자 이상, 영문 대·소문자·숫자·특수문자 포함"
        />
        <p class="auth-field-hint">8자 이상, 영문 대문자 1개 이상·소문자·숫자·특수문자 조합</p>
      </div>
      <div class="auth-field">
        <label for="reg-password-confirm">비밀번호 확인</label>
        <input
          id="reg-password-confirm"
          v-model="passwordConfirm"
          type="password"
          required
          autocomplete="new-password"
          placeholder="비밀번호 다시 입력"
        />
      </div>
      <p v-if="error" class="auth-error">{{ error }}</p>
      <button type="submit" class="auth-submit" :disabled="loading">
        {{ loading ? '가입 중...' : '회원가입' }}
      </button>
    </form>
      <p class="auth-switch">
        이미 계정이 있으신가요?
        <a :href="loginUrl">로그인</a>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import authAxios from './axios.js';

const loginUrl = '/login';

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirm = ref('');
const loading = ref(false);
const error = ref('');

function formatValidationErrors(res) {
  if (res?.errors && typeof res.errors === 'object') {
    return Object.values(res.errors).flat().join(' ');
  }
  return res?.message || '가입에 실패했습니다.';
}

const submit = async () => {
  error.value = '';
  if (password.value !== passwordConfirm.value) {
    error.value = '비밀번호가 일치하지 않습니다.';
    return;
  }
  loading.value = true;
  try {
    const { data } = await authAxios.post('/register', {
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    });
    if (data.ok && data.redirect) {
      window.location.href = data.redirect;
      return;
    }
    error.value = formatValidationErrors(data);
  } catch (err) {
    if (err.response?.status === 429) {
      error.value = '시도 횟수를 초과했습니다. 1분 후에 다시 시도해 주세요.';
    } else {
      const res = err.response?.data;
      error.value = formatValidationErrors(res) || '요청 중 오류가 발생했습니다.';
    }
  } finally {
    loading.value = false;
  }
};
</script>
