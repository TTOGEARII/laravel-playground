<template>
  <section class="shell" style="display:grid;place-items:center;padding-block:var(--s7)">
    <div class="card" style="width:100%;max-width:440px;gap:var(--s4);padding:var(--s5) var(--s4)">
      <div class="stack" style="align-items:center;text-align:center;gap:10px">
        <span class="logo-mark" style="width:56px;height:56px;font-size:26px">가</span>
        <span style="font-family:var(--disp);font-size:28px;color:var(--hd);margin-top:6px">다시 오셨네요</span>
        <span style="font-size:14px;color:var(--lead)">로그인하면 나만의 챗봇과 코드 알림을 쓸 수 있어요.</span>
      </div>

      <p v-if="socialError" class="auth-error auth-error--banner">{{ socialError }}</p>

      <form @submit.prevent="submit" class="stack g3">
        <div class="field">
          <label for="login-email">EMAIL</label>
          <input
            id="login-email"
            v-model="email"
            class="input"
            type="email"
            required
            autocomplete="email"
            placeholder="you@example.com"
          />
        </div>
        <div class="field">
          <label for="login-password">PASSWORD</label>
          <input
            id="login-password"
            v-model="password"
            class="input"
            type="password"
            required
            autocomplete="current-password"
            placeholder="••••••••"
          />
        </div>
        <div class="row" style="justify-content:space-between">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--tx2)">
            <input v-model="remember" type="checkbox" style="accent-color:var(--accent);width:16px;height:16px" />
            로그인 유지
          </label>
          <a href="#" style="font-size:13px" @click.prevent>비밀번호를 잊으셨나요?</a>
        </div>
        <p v-if="error" class="auth-error">{{ error }}</p>
        <button type="submit" class="btn btn-fill btn-block" :disabled="loading">
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

      <span style="text-align:center;font-size:13px;color:var(--tx3)">
        아직 계정이 없으신가요?
        <a :href="registerUrl">회원가입</a>
      </span>
    </div>
  </section>
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
  color: var(--text-secondary);
  font-size: 13px;
}
.auth-divider::before,
.auth-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--line);
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
