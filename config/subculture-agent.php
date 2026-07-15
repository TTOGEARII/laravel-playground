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

    /*
    | 해외 게임 명칭 교정표(잘못된 직역/기계번역 → 공식 한국어 명칭).
    | DB 에 없는 정보를 LLM 이 직접 번역하면 젠존제 무기/세트 등이 오역되는데, 이 표를
    | 시스템 프롬프트에 실어 생성 단계에서 공식 명칭을 쓰게 한다(스트리밍 출력에도 반영).
    | 새 오역 발견 시 여기에 한 줄만 추가.
    */
    'term_glossary' => [
        // 젠레스 존 제로(젠존제) W엔진·디스크·아이템
        '인공별' => '별빛기사',
        '증기오븐' => '스팀오븐',
        '불지옥 기어' => '헬파이어 기어',
    ],
    // 캐릭터 전용 장비 등 개별 사실(잘못 짐작하기 쉬운 명칭 못박기)
    'term_facts' => [
        '젠레스 존 제로 노르마의 전용 W엔진 이름은 "수석 조수"다.',
        '젠레스 존 제로 "귤복복"(橘福福/Ju Fufu)은 운규산 진영의 불속성 격파(스턴) 에이전트로, '
            .'그 자체가 정식 캐릭터다. "라이터(Lighter)"의 별명이 아니며 둘은 서로 다른 캐릭터다. '
            .'귤복복을 물으면 search_characters 로 "귤복복"을 그대로 조회해 답한다(라이터로 바꾸지 않는다).',
    ],

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
