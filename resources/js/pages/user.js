import { createApp } from 'vue';
import UserPage from './user/UserPage.vue';

const el = document.getElementById('user-app');
if (el) {
  let userData = {};
  const raw = el.getAttribute('data-user');
  if (raw) {
    try {
      userData = JSON.parse(raw);
    } catch (_) {}
  }
  createApp(UserPage, { userData }).mount(el);
}
