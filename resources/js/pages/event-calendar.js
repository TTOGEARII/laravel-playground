import { createApp } from 'vue';
import axios from 'axios';
import App from './event-calendar/App.vue';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

const el = document.getElementById('event-calendar-app');
const app = createApp(App, {
    initialEventId: el?.dataset.eventId ? Number(el.dataset.eventId) : null,
});
app.mount('#event-calendar-app');
