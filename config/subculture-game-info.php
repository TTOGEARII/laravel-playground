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
            'redeem_url_template' => 'https://hsr.hoyoverse.com/ko-kr/gift?code={code}',
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
                ['driver' => 'html', 'url' => 'https://mollulog.net/coupons'],
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
            'sources' => [
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
            'sources' => [
                ['driver' => 'html', 'url' => 'https://honeybeejoa.co.kr/bbs/board.php?bo_table=Trickcal&wr_id=1'],
                ['driver' => 'html', 'url' => 'http://www.gameinn.co.kr/news/articleView.html?idxno=12906'],
                ['driver' => 'html', 'url' => 'https://www.mumuplayer.com/kr/blog/trickcal-revive-redeem-code.html'],
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
];
