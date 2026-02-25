import { createApp } from 'vue';
import axios from 'axios';
import App from './otaku-shop/App.vue';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

const app = createApp(App);
app.mount('#otaku-shop-app');
