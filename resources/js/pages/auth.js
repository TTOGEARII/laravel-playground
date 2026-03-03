import { createApp } from 'vue';
import Login from './auth/Login.vue';
import Register from './auth/Register.vue';

const el = document.getElementById('auth-app');
if (el) {
  const page = el.getAttribute('data-page') || 'login';
  const component = page === 'register' ? Register : Login;
  createApp(component).mount(el);
}
