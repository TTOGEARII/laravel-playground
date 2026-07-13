<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 서브컬쳐 게임 AI 에이전트
    |--------------------------------------------------------------------------
    | Prism PHP(Gemini) 기반. 서브컬쳐 게임 정보(리딤코드·레이드·캐릭터·공략)만 다룬다.
    | LLM 은 config('services.gemini.model') 을 재사용(gemini-3-flash-preview).
    */

    // 에이전트 루프 최대 스텝(툴 왕복 상한 — 무한 호출·토큰 폭주 방지)
    'max_steps' => (int) env('SGA_MAX_STEPS', 5),

    // 실시간 크롤(fetch_live_page) 허용 도메인 화이트리스트.
    // 서브컬쳐 전용 보장 + SSRF 방지 — 이 목록 밖 도메인은 거부한다.
    'allowed_fetch_hosts' => [
        'mollulog.net', 'baql.net', 'letsdoro.com',
        'arca.live', 'gall.dcinside.com', 'game.naver.com', 'comm-api.game.naver.com',
        'trickcalrecord.pages.dev', 'trickcal-team-manager.netlify.app',
        'game8.co', 'pockettactics.com', 'prydwen.gg', 'hoyolab.com',
    ],

    /*
    | 페르소나는 챗봇 캐릭터(ChatCharacter) 전체에서 고른다(UI 는 카드 선택).
    | 아래 guide 는 UI 에 노출하지 않는 내부 폴백 — 옛 프리셋 세션이나
    | 캐릭터가 삭제된 세션의 말투 기본값으로만 쓰인다.
    */
    'personas' => [
        'guide' => [
            'name' => '도우미',
            'emoji' => '🤖',
            'speech' => '밝고 친절한 서브컬쳐 게임 도우미. 정보는 정확하게 전하되 말투는 다정하게, '
                .'존댓말로 간결하게 답한다.',
        ],
    ],
];
