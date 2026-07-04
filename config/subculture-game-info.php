<?php

/*
|--------------------------------------------------------------------------
| 서브컬쳐 게임 리딤코드 설정
|--------------------------------------------------------------------------
| 게임 추가 = 아래 'games' 에 블록 하나 추가(메타 + sources 목록)만 하면 된다.
| sources[].driver 는 'drivers' 에 등록된 드라이버 키:
|   ennead/seria(호요버스 JSON API) · html(표/강조 HTML 공용) · dc/arca(커뮤니티 보조)
| html 은 'url' 필요. ennead/seria/dc/arca 는 drivers 설정의 매핑을 따른다.
*/

return [
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
            'sources' => [
                ['driver' => 'ennead'],
                ['driver' => 'seria'],
                ['driver' => 'html', 'url' => 'https://game8.co/games/Genshin-Impact/archives/304759'],
                ['driver' => 'html', 'url' => 'https://www.pockettactics.com/genshin-impact/codes'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
        'starrail' => [
            'name' => '붕괴: 스타레일',
            'publisher' => 'HoYoverse',
            'icon' => '🚂',
            'color' => 'accent-indigo',
            'redeem_url_template' => 'https://hsr.hoyoverse.com/gift?code={code}',
            'redeem_note' => null,
            'region_default' => 'asia',
            'sort' => 2,
            'sources' => [
                ['driver' => 'ennead'],
                ['driver' => 'seria'],
                ['driver' => 'html', 'url' => 'https://game8.co/games/Honkai-Star-Rail/archives/410296'],
                ['driver' => 'html', 'url' => 'https://www.pockettactics.com/honkai-star-rail/codes'],
                ['driver' => 'html', 'url' => 'https://www.pcgamesn.com/honkai-star-rail/codes'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
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
            'sources' => [
                ['driver' => 'ennead'],
                ['driver' => 'seria'],
                ['driver' => 'html', 'url' => 'https://game8.co/games/Zenless-Zone-Zero/archives/435683'],
                ['driver' => 'html', 'url' => 'https://www.pcgamesn.com/zenless-zone-zero/codes'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
        'bluearchive' => [
            'name' => '블루 아카이브',
            'publisher' => 'Nexon',
            'icon' => '💙',
            'color' => 'accent-indigo',
            'redeem_url_template' => null,
            'redeem_note' => '게임 내 [계정 > 쿠폰] 또는 넥슨 쿠폰 페이지에서 입력',
            'region_default' => 'kr',
            'sort' => 4,
            'sources' => [
                ['driver' => 'naver'],
                ['driver' => 'twitter'],
                ['driver' => 'mollulog'],
                ['driver' => 'html', 'url' => 'https://www.pocketgamer.com/blue-archive/coupon-codes/'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
        'wuthering' => [
            'name' => '명조: 워더링 웨이브',
            'publisher' => 'Kuro Games',
            'icon' => '🌊',
            'color' => 'accent-teal',
            'redeem_url_template' => null,
            'redeem_note' => '게임 내 [설정 > 계정 > 리딤 코드]에서 입력 (공명자 Lv2+)',
            'region_default' => 'global',
            'sort' => 5,
            // 네이버 게임 라운지(GM 연구소 공지)를 메인 후보로, 공식 트위터·전용 코드 정리 사이트를 함께 사용.
            'sources' => [
                ['driver' => 'naver'],
                ['driver' => 'twitter'],
                ['driver' => 'html', 'url' => 'https://wuthering.gg/codes'],
                ['driver' => 'html', 'url' => 'https://wuwastatus.com/codes'],
                ['driver' => 'html', 'url' => 'https://game8.co/games/Wuthering-Waves/archives/453149'],
                ['driver' => 'html', 'url' => 'https://www.pockettactics.com/wuthering-waves/codes'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
        'trickcal' => [
            'name' => '트릭컬 리바이브',
            'publisher' => 'EPID Games',
            'icon' => '🎀',
            'color' => 'accent-pink',
            'redeem_url_template' => 'https://coupon.a.prod.service.trickcal.io/',
            'redeem_note' => 'UID + 코드 입력 (게임 내 UID 확인)',
            'region_default' => 'kr',
            'sort' => 6,
            // 한국게임: 네이버 게임 라운지(GM 공식 게시판)·공식 트위터를 메인으로, 디씨/아카는 보조·검색검증.
            'sources' => [
                ['driver' => 'naver'],
                ['driver' => 'twitter'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
        'nikke' => [
            'name' => '승리의 여신: 니케',
            'publisher' => 'Shift Up',
            'icon' => '🎯',
            'color' => 'accent-pink',
            'redeem_url_template' => 'https://www.blablalink.com/cdk',
            'redeem_note' => '공식 쿠폰 페이지(블라블라링크)에서 입력, 또는 게임 내 [메인 메뉴 > 쿠폰]',
            'region_default' => 'kr',
            'sort' => 7,
            'sources' => [
                ['driver' => 'naver'],
                ['driver' => 'twitter'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
        'browndust2' => [
            'name' => '브라운더스트2',
            'publisher' => 'Neowiz',
            'icon' => '🟤',
            'color' => 'accent-indigo',
            'redeem_url_template' => null,
            'redeem_note' => '게임 내 [설정 > 쿠폰 등록] 또는 공식 쿠폰 페이지에서 입력',
            'region_default' => 'kr',
            'sort' => 8,
            'sources' => [
                ['driver' => 'naver'],
                ['driver' => 'twitter'],
                ['driver' => 'dc'],
                ['driver' => 'arca'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 드라이버별 엔드포인트/매핑
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'ennead' => [
            'base' => env('SGI_ENNEAD_BASE', 'https://api.ennead.cc/mihoyo'),
            'games' => ['genshin' => 'genshin', 'starrail' => 'starrail', 'zenless' => 'zenless'],
        ],
        'seria' => [
            'base' => env('SGI_SERIA_BASE', 'https://hoyo-codes.seria.moe'),
            'games' => ['genshin' => 'genshin', 'starrail' => 'hkrpg', 'zenless' => 'nap'],
        ],
        'dc' => [
            'base' => 'https://gall.dcinside.com/mgallery/board/lists/',
            'galleries' => [
                'genshin' => 'onshinproject',
                'starrail' => 'staraiload',
                'zenless' => 'zenless_zone_zero',
                'bluearchive' => 'projectmx',
                'wuthering' => 'wutheringwaves',
                'trickcal' => 'rollthechess',
                'nikke' => 'victorynikke',
                'browndust2' => 'browndust2',
            ],
        ],
        'arca' => [
            'base' => 'https://arca.live/b/',
            'channels' => [
                'genshin' => 'genshin',
                'starrail' => 'hkstarrail',
                'zenless' => 'zenlesszonezero',
                'bluearchive' => 'bluearchive',
                'wuthering' => 'wutheringwaves',
                'trickcal' => 'trickcal',
                'nikke' => 'nikketgv',
                'browndust2' => 'browndust',
            ],
            // 게임별 쿠폰 카테고리(지정 시: 해당 카테고리의 '최근 recent_days일' 글에서만 코드 수집).
            'categories' => [
                'nikke' => '쿠폰',
            ],
            'recent_days' => (int) env('SGI_ARCA_RECENT_DAYS', 7),
        ],

        // 게임 공식 트위터(X). nitter RSS 로 접근(X 본 사이트는 로그인 벽). accounts = 게임슬러그 → 계정.
        // 공식 KR 계정이 있는 게임만 등록(호요버스는 KR 공식 계정이 없고 API로 이미 커버되어 제외).
        'twitter' => [
            'nitter_base' => env('SGI_NITTER_BASE', 'https://nitter.net'),
            'accounts' => [
                'wuthering' => 'WW_KR_Official',
                'bluearchive' => 'KR_BlueArchive',
                'nikke' => 'NIKKE_kr',
                'browndust2' => 'browndust2_kr',
                'trickcal' => 'Trickcal_RE',
            ],
        ],

        // 네이버 게임 라운지(한국게임 공식 쿠폰). lounges = 게임슬러그 → 라운지 ID.
        'naver' => [
            'base' => env('SGI_NAVER_BASE', 'https://comm-api.game.naver.com/nng_main/v1'),
            'feed_limit' => (int) env('SGI_NAVER_FEED_LIMIT', 20),
            'lounges' => [
                'bluearchive' => 'Blue_Archive',
                'trickcal' => 'Trickcal',
                'nikke' => 'nikke',
                'browndust2' => 'BrownDust2',
                'wuthering' => 'WutheringWaves',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP 설정 (실제 브라우저 UA — 봇 UA는 일부 사이트가 403/짧은 응답)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'timeout' => 15,
        'retry' => 2,
    ],

    // 수집 시 만료된 코드는 새로 저장하지 않는다(사용 가능한 코드만 저장).
    'store_usable_only' => true,

    /*
    |--------------------------------------------------------------------------
    | 커뮤니티 검색 교차검증 (수집 후 디씨/아카에서 코드를 '검색'해 한 번 더 검증)
    |--------------------------------------------------------------------------
    | API가 없는 게임(트릭컬/블아/명조)은 정적 사이트의 오래된 코드를 살아있는 것처럼
    | 수집하기 쉽다. 그래서 미검증 코드를 디씨 갤러리·아카 채널에서 직접 검색해:
    |   - 최근 글에서 보이면 corroboration(교차검증) +1  → 신뢰도 상승
    |   - 글 제목에 '만료/종료/expired' 가 함께 보이면 만료 처리
    | 코드마다 요청이 1~2건 늘어나므로 게임당 상한·간격을 둔다.
    */
    'verify' => [
        'enabled' => (bool) env('SGI_VERIFY_SEARCH', true),
        'max_codes_per_game' => (int) env('SGI_VERIFY_MAX_PER_GAME', 20),
        'recency_days' => (int) env('SGI_VERIFY_RECENCY_DAYS', 45),
        'delay_ms' => (int) env('SGI_VERIFY_DELAY_MS', 400),
    ],
];
