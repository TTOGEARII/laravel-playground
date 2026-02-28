import { createApp } from 'vue';
import Chat from './my-wife-bot/Chat.vue';

const el = document.getElementById('my-wife-bot-chat-app');
if (el) {
    let character = {};
    const dataEl = document.getElementById('my-wife-bot-chat-data');
    if (dataEl && dataEl.textContent) {
        try {
            character = JSON.parse(dataEl.textContent);
        } catch (_) {}
    }
    createApp(Chat, { character }).mount(el);
}
