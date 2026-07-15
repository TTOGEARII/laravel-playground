<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 외부 봇 차단 정책 (BlockExternalBots 미들웨어)
    |--------------------------------------------------------------------------
    | 외부(공인 IP)에서 오는 봇 UA 를 403 으로 막는다. 내부(루프백·사설 대역)는
    | 항상 통과 — 배포 헬스체크(도커 게이트웨이)·모니터링을 보호한다.
    | 정상 브라우저는 봇 UA 가 아니므로 영향 없다.
    |
    | 판정 순서: (1) 내부 IP 면 통과 → (2) allow 에 걸리면 통과(검색엔진·링크 미리보기)
    |          → (3) block 에 걸리면 차단 → (4) 그 외 통과.
    | 매칭은 User-Agent 를 소문자로 만든 뒤 부분 문자열(str_contains) 비교.
    */
    'bots' => [
        // 허용 — block 패턴에 걸려도 이건 통과시킨다.
        'allow' => [
            // 정식 검색엔진 크롤러(색인·검색 노출 = SEO 유지)
            'googlebot',
            'google-inspectiontools',
            'storebot-google',
            'bingbot',
            'yeti',        // 네이버
            'naver',
            'daum',        // 다음/카카오
            'daumoa',
            'duckduckbot',
            'applebot',
            'yandex',
            'baiduspider',

            // 링크 미리보기 — 사람이 메신저/SNS 에 공유할 때 트리거(스크래퍼 아님).
            // 막으면 공유 시 미리보기 카드가 안 뜬다.
            'kakaotalk-scrap',
            'facebookexternalhit',
            'twitterbot',
            'slackbot',
            'discordbot',
            'telegrambot',
            'line-poker',
        ],

        // 차단 — 스크래퍼·자동 HTTP 클라이언트·AI 데이터 크롤러.
        'block' => [
            // 자동 HTTP 클라이언트(스크립트)
            'curl',
            'wget',
            'python-requests',
            'python-urllib',
            'go-http-client',
            'okhttp',
            'axios',
            'node-fetch',
            'libwww',
            'httpclient',
            'java/',
            'guzzle',
            'httpx',
            'aiohttp',

            // 헤드리스/자동 브라우저·스캐너
            'headlesschrome',
            'phantomjs',
            'scrapy',
            'httrack',
            'masscan',
            'zgrab',
            'nikto',
            'nmap',

            // AI 데이터 수집 크롤러
            'gptbot',
            'oai-searchbot',
            'chatgpt-user',
            'ccbot',
            'claudebot',
            'anthropic-ai',
            'google-extended',
            'amazonbot',
            'bytespider',
            'perplexitybot',
            'diffbot',
            'omgili',

            // SEO/마케팅 대량 크롤러(트래픽만 축내는 봇)
            'ahrefsbot',
            'semrushbot',
            'mj12bot',
            'dotbot',
            'petalbot',
            'seznambot',
            'megaindex',
            'serpstatbot',
            'dataforseo',
            'blexbot',

            // 일반 봇 키워드(위 검색엔진 allow 를 거친 뒤의 최종 폴백)
            'bot',
            'crawl',
            'spider',
            'slurp',
            'scraper',
            'embedly',
            'pingdom',
            'uptimerobot',
            'lighthouse',
        ],
    ],
];
