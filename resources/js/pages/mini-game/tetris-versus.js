import { createApp } from 'vue';
import App from './tetris-versus/App.vue';
import '../../../css/pages/mini-game/tetris-versus.css';

const el = document.getElementById('tetris-versus-app');
if (el) {
    createApp(App, {
        me: { id: Number(el.dataset.userId), name: el.dataset.userName },
        homeUrl: el.dataset.homeUrl,
        createRoomUrl: el.dataset.createRoomUrl,
        csrf: el.dataset.csrf,
    }).mount('#tetris-versus-app');
}
