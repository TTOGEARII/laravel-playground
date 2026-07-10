import { createApp } from 'vue';
import axios from 'axios';
import App from './otaku-shop/App.vue';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

const el = document.getElementById('otaku-shop-app');
const app = createApp(App, { loggedIn: !!el?.dataset.loggedIn });
app.mount('#otaku-shop-app');
