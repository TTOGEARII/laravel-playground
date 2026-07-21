import { createApp } from 'vue';
import App from './tetris-battle/BattleApp.vue';
import '../../../css/pages/mini-game/tetris-battle.css';

const el = document.getElementById('tetris-battle-app');
if (el) {
    createApp(App, {
        me: { id: Number(el.dataset.userId), name: el.dataset.userName },
        homeUrl: el.dataset.homeUrl,
        createRoomUrl: el.dataset.createRoomUrl,
        matchmakeUrl: el.dataset.matchmakeUrl,
        csrf: el.dataset.csrf,
        maxPlayers: Number(el.dataset.maxPlayers || 6),
    }).mount('#tetris-battle-app');
}
