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
            'pages' => 8,          // 12건/페이지 — 티스토리 발견분과의 매칭(상세 승격)을 위해 넓게. 기존 글은 상세 재방문 생략이라 부담 낮음
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
        // J-pop 내한 캘린더(j-pop-playlist.tistory.com/1109) — 큐레이션 J-pop 전용(장르 확정 수집).
        // 위젯 pill 의 data 속성(date/title/location/link)을 사이드카로 추출. festivallife 와
        // 겹치는 공연은 sync 의 교차 소스 중복 방지가 거른다(먼저 온 쪽 유지).
        'jpoptistory' => ['enabled' => true],
        // 전시장 캘린더(킨텍스·SETEC·코엑스) — 게임사 오프라인 행사(블아 페스티벌 등)·동인 행사가 잡힌다.
        // 산업 전시가 대부분이라 아래 venues.keywords/hosts 포지티브 필터로 서브컬쳐만 수집.
        'kintex' => ['enabled' => true, 'base' => 'https://www.kintex.com'],
        'setec' => ['enabled' => true, 'base' => 'https://www.setec.or.kr'],
        'coex' => ['enabled' => true, 'base' => 'https://www.coex.co.kr'],
        // 네이버 게임 라운지 공지 — 전시장에 안 잡히는 팝업스토어·콜라보 카페·오케스트라(정찰 실증:
        // 니케 여름 팝업스토어 공지). 제목 키워드 + 본문 기간 파싱, 같은 행사의 연속 공지(사전/현장 안내)는
        // 기간 키로 중복 제거. board 는 각 라운지의 공지/뉴스 게시판 ID(정찰 실측).
        'lounge' => [
            'enabled' => true,
            'base' => 'https://comm-api.game.naver.com/nng_main/v1',
            'lounges' => [
                ['lounge' => 'nikke', 'board' => 11, 'label' => '니케'],
                ['lounge' => 'Blue_Archive', 'board' => 6, 'label' => '블루 아카이브'],
            ],
            // 오프라인 행사 신호 키워드(제목) — 게임 내 이벤트 공지와 구분되는 것만
            'keywords' => ['팝업', '카페', '오프라인', '오케스트라', '콘서트', '전시회', '페스티벌', '현장'],
            // 제외 키워드 — 온라인 상영/스트리밍 공지는 오프라인 행사가 아님
            'exclude_keywords' => ['온라인', '스트리밍', 'vod'],
        ],
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
