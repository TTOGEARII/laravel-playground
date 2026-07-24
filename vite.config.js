import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import obfuscator from 'vite-plugin-javascript-obfuscator';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/pages/otaku-shop.js', 'resources/js/pages/my-wife-bot.js', 'resources/js/pages/auth.js', 'resources/js/pages/user.js', 'resources/js/pages/mini-game/vampire-survival.js', 'resources/js/pages/mini-game/tetris-versus.js', 'resources/js/pages/mini-game/tetris-battle.js', 'resources/js/pages/subculture-info.js', 'resources/js/pages/subculture-agent.js', 'resources/js/pages/event-calendar.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
        // 미니게임(뱀서) 코드만 프로덕션 빌드 시 난독화 — 소스 읽기/콘솔 조작을 어렵게.
        // 게임 루프 성능을 위해 controlFlowFlattening/deadCodeInjection 등 무거운 변환은 끄고,
        // 밸런스 로그(console)는 유지한다.
        obfuscator({
            include: ['resources/js/pages/mini-game/**/*.js'],
            exclude: [/node_modules/],
            apply: 'build',
            options: {
                compact: true,
                identifierNamesGenerator: 'hexadecimal',
                simplify: true,
                stringArray: true,
                stringArrayThreshold: 0.6,
                stringArrayEncoding: ['base64'],
                controlFlowFlattening: false,
                deadCodeInjection: false,
                debugProtection: false,
                selfDefending: false,
                disableConsoleOutput: false,
            },
        }),
    ],
    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
