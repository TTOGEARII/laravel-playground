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

            // 헤드리스/자동 브라우저·스캐너·공격 도구
            'headlesschrome',
            'phantomjs',
            'scrapy',
            'httrack',
            'masscan',
            'zgrab',
            'nikto',
            'nmap',
            'sqlmap',
            'nuclei',
            'wpscan',
            'dirbuster',
            'gobuster',
            'feroxbuster',
            'ffuf',
            'wfuzz',
            'censys',
            'internetmeasurement',
            'heritrix',      // 아카이브 크롤러(전 페이지 고속 훑음) — 봇 키워드에 안 걸려 누락됐던 것
            'ia_archiver',
            'archive.org_bot',

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

    /*
    |--------------------------------------------------------------------------
    | 공격 시그니처 (BlockExternalBots 미들웨어)
    |--------------------------------------------------------------------------
    | 요청 URL(경로+쿼리)을 소문자로 만들고 URL 디코드한 뒤, 아래 조각이 하나라도
    | 들어 있으면 UA·IP 무관 403 으로 막는다. LFI/경로탐색·XSS·SQLi·알려진 취약경로 스캔 등
    | "정상 브라우저가 절대 만들지 않는" 고신뢰 패턴만 넣어 오탐을 줄인다.
    | (원본 URI 와 디코드 URI 양쪽을 검사 — 인코딩 회피 방지)
    */
    'attack_signatures' => [
        // LFI · 경로 탐색 · 래퍼 (64.89.161.83 유형: .env·wp-config 탈취 시도)
        'php://', 'file://', 'data://', 'expect://', 'phar://',
        '../', '..\\', '..%2f', '..%5c',
        '/etc/passwd', '/etc/shadow', 'proc/self/environ',
        'wp-config', '/.env', '.env%', '.aws/credentials', '.ssh/id_rsa', '/.git/',
        'convert.base64-encode', 'base64_decode',

        // XSS (앵글브래킷 breakout·스크립트·이벤트 핸들러)
        '"><', "'><", '<script', '</script', 'javascript:', 'onerror=', 'onload=',

        // SQL 인젝션
        'union select', 'union all select', "' or '1'='1", "' or 1=1", ' or 1=1--',
        'sleep(', 'benchmark(', 'waitfor delay', 'pg_sleep(',

        // 알려진 취약 경로·RCE 스캔
        '${jndi:', 'jndi:ldap', 'xmlrpc.php', 'wp-login.php', '/wp-admin', '/phpmyadmin',
        '/vendor/phpunit', 'eval-stdin.php', 'boaform', '/hnap1', 'think\\app', '/solr/',
        '/actuator/', '/cgi-bin/',
    ],
];
