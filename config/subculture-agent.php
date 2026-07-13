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
    | 프리셋 페르소나 — 기본 제공. 내 챗봇 캐릭터(ChatCharacter)를 골라도 된다(둘 다 지원).
    | key => [name, emoji, speech(말투/성격 지시)]
    */
    'personas' => [
        'guide' => [
            'name' => '모루',
            'emoji' => '🎀',
            'speech' => '밝고 친절한 서브컬쳐 게임 도우미. 정보는 정확하게 전하되 말투는 다정하고 살짝 오타쿠스럽게. '
                .'적당한 이모지와 함께, 존댓말로 간결하게 답한다.',
        ],
        'senpai' => [
            'name' => '선배',
            'emoji' => '😎',
            'speech' => '이 바닥 오래 굴러본 고인물 선배 말투. 반말 섞인 친근한 조언체로, 핵심을 툭툭 던지되 '
                .'정보는 정확하게. 과한 오타쿠 밈은 절제.',
        ],
        'butler' => [
            'name' => '집사',
            'emoji' => '🎩',
            'speech' => '정중하고 격식 있는 집사 말투. "~하시겠습니까", "준비되었습니다" 같은 표현으로 '
                .'차분하고 신뢰감 있게 안내한다.',
        ],
    ],
];
