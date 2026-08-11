<template>
  <section class="shell" style="display:grid;place-items:center;padding-block:var(--s6)">
    <div class="card" style="width:100%;max-width:460px;gap:var(--s4);padding:var(--s5) var(--s4)">
      <div class="stack" style="align-items:center;text-align:center;gap:10px">
        <span class="logo-mark" style="width:56px;height:56px;font-size:26px">가</span>
        <span style="font-family:var(--disp);font-size:28px;color:var(--hd);margin-top:6px">같이 덕질하실래요?</span>
        <span style="font-size:14px;color:var(--lead)">가입하면 나만의 챗봇, 코드 교환 체크, 게임 랭킹을 쓸 수 있어요.</span>
      </div>

      <form @submit.prevent="submit" class="stack g3">
        <div class="field">
          <label for="reg-name">닉네임</label>
          <input
            id="reg-name"
            v-model="name"
            class="input"
            type="text"
            required
            autocomplete="name"
            placeholder="사이트에서 보일 이름"
          />
        </div>
        <div class="field">
          <label for="reg-email">EMAIL</label>
          <input
            id="reg-email"
            v-model="email"
            class="input"
            type="email"
            required
            autocomplete="email"
            placeholder="you@example.com"
          />
        </div>
        <div class="field">
          <label for="reg-password">PASSWORD</label>
          <input
            id="reg-password"
            v-model="password"
            class="input"
            type="password"
            required
            autocomplete="new-password"
            placeholder="8자 이상"
          />
          <p class="auth-field-hint">8자 이상, 영문 대문자 1개 이상·소문자·숫자·특수문자 조합</p>
        </div>
        <div class="field">
          <label for="reg-password-confirm">PASSWORD 확인</label>
          <input
            id="reg-password-confirm"
            v-model="passwordConfirm"
            class="input"
            type="password"
            required
            autocomplete="new-password"
            placeholder="다시 한 번"
          />
        </div>
        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--tx2);line-height:1.7" for="reg-agree">
          <input id="reg-agree" v-model="agree" type="checkbox" style="accent-color:var(--accent);width:16px;height:16px;margin-top:4px" />
          <span>
            <a :href="termsUrl" target="_blank" rel="noopener">이용약관</a> 및
            <a :href="privacyUrl" target="_blank" rel="noopener">개인정보처리방침</a>에 동의합니다.
            <em class="auth-agree-required">(필수)</em>
          </span>
        </label>
        <p v-if="error" class="auth-error">{{ error }}</p>
        <button type="submit" class="btn btn-fill btn-block" :disabled="loading || !agree">
          {{ loading ? '가입 중...' : '가입하기' }}
        </button>
      </form>

      <span style="text-align:center;font-size:13px;color:var(--tx3)">
        이미 계정이 있으신가요?
        <a :href="loginUrl">로그인</a>
      </span>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import authAxios from './axios.js';

const loginUrl = '/login';
const termsUrl = '/terms';
const privacyUrl = '/privacy';

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirm = ref('');
const agree = ref(false);
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
  if (!agree.value) {
    error.value = '개인정보 수집·이용 및 이용약관에 동의해 주세요.';
    return;
  }
  loading.value = true;
  try {
    const { data } = await authAxios.post('/register', {
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
      agree: agree.value,
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
