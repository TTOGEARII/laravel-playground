<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 게임 카탈로그 (subculture_games 테이블 시드 기준)
    |--------------------------------------------------------------------------
    | collect 커맨드가 실행될 때 이 정의로 subculture_games 를 upsert 한다.
    | redeem_url_template 의 {code} 는 원클릭 교환 직링크 생성 시 치환된다.
    | (인게임 전용/로그인 폼이라 직링크가 무의미하면 null + redeem_note 안내)
    */
    'games' => [
        'genshin' => [
            'name' => '원신',
            'publisher' => 'HoYoverse',
            'icon' => '⛩️',
            'color' => 'accent-teal',
            'redeem_url_template' => 'https://genshin.hoyoverse.com/ko/gift?code={code}',
            'redeem_note' => null,
            'region_default' => 'asia',
            'sort' => 1,
        ],
        'starrail' => [
            'name' => '붕괴: 스타레일',
            'publisher' => 'HoYoverse',
            'icon' => '🚂',
            'color' => 'accent-indigo',
            'redeem_url_template' => 'https://hsr.hoyoverse.com/ko-kr/gift?code={code}',
            'redeem_note' => null,
            'region_default' => 'asia',
            'sort' => 2,
        ],
        'zenless' => [
            'name' => '젠레스 존 제로',
            'publisher' => 'HoYoverse',
            'icon' => '🏙️',
            'color' => 'accent-pink',
            'redeem_url_template' => 'https://zenless.hoyoverse.com/redemption?code={code}',
            'redeem_note' => null,
            'region_default' => 'asia',
            'sort' => 3,
        ],
        'bluearchive' => [
            'name' => '블루 아카이브',
            'publisher' => 'Nexon',
            'icon' => '💙',
            'color' => 'accent-indigo',
            'redeem_url_template' => null, // 넥슨 쿠폰페이지는 로그인 폼 → 직링크 불가
            'redeem_note' => '게임 내 [계정 > 쿠폰] 또는 넥슨 쿠폰 페이지에서 입력',
            'region_default' => 'kr',
            'sort' => 4,
        ],
        'wuthering' => [
            'name' => '명조: 워더링 웨이브',
            'publisher' => 'Kuro Games',
            'icon' => '🌊',
            'color' => 'accent-teal',
            'redeem_url_template' => null, // 공식 웹 리딤 없음(인게임 전용)
            'redeem_note' => '게임 내 [설정 > 계정 > 리딤 코드]에서 입력 (공명자 Lv2+)',
            'region_default' => 'global',
            'sort' => 5,
        ],
        'trickcal' => [
            'name' => '트릭컬 리바이브',
            'publisher' => 'EPID Games',
            'icon' => '🎀',
            'color' => 'accent-pink',
            'redeem_url_template' => 'https://coupon.a.prod.service.trickcal.io/', // UID 입력 폼(코드 프리필 불가) → 페이지로만 연결
            'redeem_note' => 'UID + 코드 입력 (게임 내 UID 확인)',
            'region_default' => 'kr',
            'sort' => 6,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 수집 소스
    |--------------------------------------------------------------------------
    */
    'sources' => [
        // 호요버스 3종: 비공식 JSON 집계 API (정적 크롤 불필요)
        'hoyoverse_api' => [
            'base' => env('SGI_ENNEAD_BASE', 'https://api.ennead.cc/mihoyo'),
            // 게임 slug => ennead 경로 segment
            'endpoints' => [
                'genshin' => 'genshin',
                'starrail' => 'starrail',
                'zenless' => 'zenless',
            ],
        ],

        // 메인 정리 사이트(정적 HTML) — 게임 slug => URL(들). 여러 개면 순서대로 모두 수집(폴백/보강).
        'aggregators' => [
            'bluearchive' => [
                'https://mollulog.net/coupons',
            ],
            'wuthering' => [
                'https://wuthering.gg/codes',
            ],
            'trickcal' => [
                'https://honeybeejoa.co.kr/bbs/board.php?bo_table=Trickcal&wr_id=1',
                'http://www.gameinn.co.kr/news/articleView.html?idxno=12906',
            ],
        ],

        // 커뮤니티(보조 신호) — 디씨인사이드 마이너 갤러리 목록
        'community' => [
            'dc' => [
                'enabled' => env('SGI_DC_ENABLED', true),
                'base' => 'https://gall.dcinside.com/mgallery/board/lists/',
                'galleries' => [
                    'genshin' => 'onshinproject',
                    'starrail' => 'staraiload',
                    'zenless' => 'zenless_zone_zero',
                    'bluearchive' => 'projectmx',
                    'wuthering' => 'wutheringwaves',
                    'trickcal' => 'rollthechess',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP 설정 (실제 브라우저 UA — 봇 UA는 일부 사이트가 403 반환)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'timeout' => 15,
        'retry' => 2,
    ],

    // 코드로 인정할 토큰 패턴(대문자/숫자 4~30자). 의미형/소문자 혼용 대비 케이스는 보존.
    'code_pattern' => '/\b[A-Z0-9]{4,30}\b/',
];
