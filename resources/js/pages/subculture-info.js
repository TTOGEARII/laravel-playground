import { createApp } from 'vue';
import axios from 'axios';
import App from './subculture-info/App.vue';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

const mountEl = document.querySelector('#subculture-info-app');
const app = createApp(App, {
    games: JSON.parse(mountEl.dataset.games ?? '[]'),
    loggedIn: mountEl.dataset.loggedIn === '1',
});
app.mount('#subculture-info-app');
