import { createApp } from 'vue';
import App from './subculture-agent/App.vue';

const el = document.getElementById('subculture-agent-app');
if (el) {
  createApp(App, {
    enabled: !!el.dataset.enabled,
    loggedIn: !!el.dataset.loggedIn,
    games: JSON.parse(el.dataset.games ?? '{}'),
  }).mount(el);
}
