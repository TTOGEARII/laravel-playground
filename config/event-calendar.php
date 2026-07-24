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
        // 전시장 캘린더(킨텍스·SETEC·코엑스) — 게임사 오프라인 행사(블아 페스티벌 등)·동인 행사가 잡힌다.
        // 산업 전시가 대부분이라 아래 venues.keywords/hosts 포지티브 필터로 서브컬쳐만 수집.
        'kintex' => ['enabled' => true, 'base' => 'https://www.kintex.com'],
        'setec' => ['enabled' => true, 'base' => 'https://www.setec.or.kr'],
        'coex' => ['enabled' => true, 'base' => 'https://www.coex.co.kr'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 전시장 수집 공통(서브컬쳐 판별 — 단일 출처)
    |--------------------------------------------------------------------------
    | 미탐 발견 시 keywords/hosts 에 한 줄 추가하면 다음 수집부터 잡힌다.
    */
    'venues' => [
        'window_days' => 120,  // 오늘 ~ +N일 슬라이딩 윈도우
        'delay_ms' => 1000,    // 상세 요청 간 딜레이
        // 서브컬쳐 포지티브 키워드(행사명) — 하나라도 포함되면 수집
        'keywords' => [
            '코믹', '동인', '일러스트', '팝콘', '애니메이션', '만화', '웹툰', '굿즈', '코스프레', '서브컬쳐',
            '온리전', '디페스타', '디.페스타', '보컬로이드', '미쿠', '버튜버', '스텔라이브', '성우',
            '블루 아카이브', '블루아카이브', '니케', '트릭컬', '원신', '스타레일', '젠레스', '명조', '호요',
            '결속밴드', '봇치', '지스타',
        ],
        // 주최 화이트리스트(상세의 주최 필드) — 행사명에 키워드가 없어도 주최가 서브컬쳐 업체면 수집
        'hosts' => ['코믹월드', '동인네트워크', '넥슨게임즈', '스타라이크', '오씨메이커스', '요스타', '스마일게이트'],
        // 전용 소스가 이미 있는 행사(중복 방지 — comicworld/illustar 소스가 담당)
        'dedupe_keywords' => ['코믹월드', '일러스타', '문구전'],
        // 행사 종류 판별(행사명) — 그 외는 expo
        'kind_keywords' => [
            'concert' => ['콘서트', '라이브', '단독공연', '내한', '리사이틀'],
            'doujin' => ['동인', '온리전', '디페스타', '디.페스타', '코믹'],
        ],
    ],

    // 수집 HTTP 요청 공통 User-Agent(실브라우저 — 아임웹 UA 필터 통과용)
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
];
