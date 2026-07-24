<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 행사 캘린더 수집 소스 설정 (단일 출처)
    |--------------------------------------------------------------------------
    | 정찰 실측(2026-07) 기반:
    | - festivallife: 아임웹 SSR, 비브라우저 UA 403 → 브라우저 UA 필수. robots 허용.
    |   /concert_k 는 장르 무관 전체 내한공연 → 전부 수집 후 Gemini 로 장르(jpop/other) 태깅.
    | - comicworld: 그누보드, 홈 테이블을 채우는 숨은 JSON API(POST d/ajax.main.php).
    |   type=comic(코믹월드)·mongu(문구전). 날짜 ISO. robots 위반 아님(Disallow 2경로뿐).
    | - illustar: React SPA → Playwright 사이드카(4단계에서 추가).
    | - AGF: 연 1회라 크롤러 없이 수동 임포트(event-calendar:import).
    */
    'sources' => [
        'festivallife' => [
            'enabled' => true,
            'base_url' => 'https://festivallife.kr',
            'board' => 'concert_k',
            'pages' => 3,          // 증분 수집 페이지 수(12건/페이지, 신규 주 3~4건이라 충분)
            'delay_ms' => 1200,    // 상세 페이지 요청 간 딜레이(정중한 크롤)
        ],
        'comicworld' => [
            'enabled' => true,
            'endpoint' => 'https://comicw.net/d/ajax.main.php',
            'types' => ['comic', 'mongu'], // 코믹월드 / 문구전
        ],
        'illustar' => [
            'enabled' => true, // Playwright 사이드카(event-illustar.mjs) — 배너 텍스트 구조 기반
        ],
    ],

    // 수집 HTTP 요청 공통 User-Agent(실브라우저 — 아임웹 UA 필터 통과용)
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
];
