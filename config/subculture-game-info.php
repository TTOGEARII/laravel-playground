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
                'browndust2' => 'browndust2', // 주의: /b/browndust 는 브더1 채널
            ],
            // 게임별 쿠폰 카테고리(지정 시: 해당 카테고리의 '최근 recent_days일' 글에서만 코드 수집).
            'categories' => [
                'nikke' => '쿠폰',
            ],
            'recent_days' => (int) env('SGI_ARCA_RECENT_DAYS', 7),
            // 공략글 수집용 카테고리(채널별 실측). 추천글(mode=best)은 팬아트 위주라
            // 공략 전용 카테고리를 함께 수집해야 대체 캐릭터 추출 재료가 확보된다.
            'guide_categories' => [
                // 블아 '택틱' 카테고리는 명칭과 달리 규제 일지 연재가 점거 중이라 '정보'만 사용
                'bluearchive' => ['정보'],
                'nikke' => ['솔로레이드', '정보', '협동작전'],
                'trickcal' => ['공략'],
                'browndust2' => ['브라운정보'],
            ],
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

    // 만료된 지 이 일수를 넘긴 코드는 수집 때 DB에서 삭제(유예기간). 재검증·직전 만료 확인 여지용.
    'prune_grace_days' => (int) env('SGI_PRUNE_GRACE_DAYS', 7),

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

    /*
    |--------------------------------------------------------------------------
    | 레이드 정보 통합 (보스 일정·추천 편성·공략글·내 캐릭터 풀)
    |--------------------------------------------------------------------------
    | 캐릭터/레이드 마스터는 서드파티 사이트를 Playwright 사이드카(tools/raid-crawler)로
    | 크롤한다. 호요버스 게임은 인게임 제공이라 대상에서 제외.
    | 공략글은 디씨 개념글·아카 추천글 목록을 HTTP 로 수집(갤러리/채널 매핑은
    | 위 drivers.dc.galleries / drivers.arca.channels 재사용).
    */
    'raids' => [
        // 레이드 기능 대상 게임(위 games 키의 부분집합)
        'games' => ['bluearchive', 'nikke', 'trickcal', 'browndust2'],

        'crawler' => [
            'node_binary' => env('SGI_CRAWLER_NODE', 'node'),
            'script' => base_path('tools/raid-crawler/index.mjs'),
            // 브라우저 설치 경로. Sail 이미지가 PLAYWRIGHT_BROWSERS_PATH=0 을 컨테이너 env 로
            // 심어두므로(.env 보다 우선) 전용 키로 명시하고 러너가 프로세스에 직접 주입한다.
            // 설치: PLAYWRIGHT_BROWSERS_PATH=<이 경로> npx playwright install chromium
            'browsers_path' => env('SGI_PLAYWRIGHT_BROWSERS_PATH', storage_path('app/playwright')),
            // 원격 Playwright 브라우저 WS 엔드포인트(선택). config:cache 환경에서도 살아있도록
            // 러너에서 env() 직접 호출 대신 반드시 이 키를 거친다.
            'playwright_ws' => env('SGI_PLAYWRIGHT_WS'),
            'timeout' => (int) env('SGI_CRAWLER_TIMEOUT', 300), // Playwright 렌더링 대기 감안(초)
            'sources' => [
                'bluearchive' => ['source' => 'mollulog', 'base' => 'https://mollulog.net'],
                'nikke' => ['source' => 'letsdoro', 'base' => 'https://letsdoro.com'],
                'trickcal' => ['source' => 'triplelab', 'base' => 'https://tr.triple-lab.com'],
                'browndust2' => ['source' => 'souseha', 'base' => 'https://browndust2-db.souseha.com'],
            ],
            // 이번 수집량이 기존 활성 캐릭터 수의 이 비율 미만이면(마크업 깨짐 의심)
            // 미등장 캐릭터 비활성화를 건너뛴다.
            'deactivate_guard_ratio' => 0.5,
        ],

        // 게임별 성장도 입력 스키마 — user_characters.growth JSON 의 계약.
        // Vue 동적 폼 렌더링과 Form Request 동적 검증이 모두 이 정의를 따른다.
        'growth_fields' => [
            'bluearchive' => [
                ['key' => 'star', 'label' => '성급', 'type' => 'select', 'options' => [1, 2, 3, 4, 5]],
                ['key' => 'weapon_star', 'label' => '전용무기', 'type' => 'select', 'options' => [0, 1, 2, 3]],
                ['key' => 'level', 'label' => '레벨', 'type' => 'number', 'min' => 1, 'max' => 90],
                ['key' => 'gear_tier', 'label' => '장비 티어', 'type' => 'number', 'min' => 0, 'max' => 10],
            ],
            'nikke' => [
                ['key' => 'level', 'label' => '레벨', 'type' => 'number', 'min' => 1, 'max' => 999],
                ['key' => 'core', 'label' => '돌파/코어', 'type' => 'select', 'options' => ['미돌파', '1돌', '2돌', '3돌', '코어1', '코어2', '코어3', '코어4', '코어5', '코어6', '코어7']],
                ['key' => 'skill1', 'label' => '스킬1', 'type' => 'number', 'min' => 1, 'max' => 10],
                ['key' => 'skill2', 'label' => '스킬2', 'type' => 'number', 'min' => 1, 'max' => 10],
                ['key' => 'burst', 'label' => '버스트 스킬', 'type' => 'number', 'min' => 1, 'max' => 10],
            ],
            'trickcal' => [
                ['key' => 'star', 'label' => '성급', 'type' => 'select', 'options' => [1, 2, 3, 4, 5]],
                ['key' => 'level', 'label' => '레벨', 'type' => 'number', 'min' => 1, 'max' => 300],
            ],
            'browndust2' => [
                ['key' => 'plus', 'label' => '돌파(+)', 'type' => 'select', 'options' => [0, 1, 2, 3, 4, 5]],
                ['key' => 'level', 'label' => '레벨', 'type' => 'number', 'min' => 1, 'max' => 120],
            ],
        ],

        // 블아 종합전술시험(종전시) — 아카 ZiWu 시리즈 글 결정적 파싱(Gemini 불필요).
        // 모음글에서 차수 메타(기간·종류/장갑/지형·기믹)와 공략글 링크를 얻고,
        // 각 공략글의 참고 영상 표(총점/영상/3파티×6명+스펙)를 파싱해 Raid+RaidParty 로 저장.
        'jfd' => [
            'archive_url' => 'https://arca.live/b/bluearchive/113654108',
            'source' => 'arca-jfd',
            // 공략글당 저장할 참고 영상(엔트리) 수 — 엔트리당 3파티
            'top_entries' => (int) env('SGI_JFD_TOP_ENTRIES', 4),
            'fetch_delay_seconds' => (float) env('SGI_JFD_FETCH_DELAY', 1.0),
            // 커뮤니티 애칭 → 마스터 이름 (접두 변형 규칙으로 못 푸는 것만 수동 등록.
            // 수집 후 커맨드가 출력하는 '미해석 애칭' 목록을 보고 여기에 추가한다)
            'aliases' => [
                '수기사' => '키사키(수영복)',
                '수로코' => '시로코(수영복)',
                '수시노' => '호시노(수영복)',
                '수이아' => '세이아(수영복)',
                '수사키' => '사키(수영복)',
                '수시로' => '마시로(수영복)',
                '수미카' => '미카(수영복)',
                '수오리' => '사오리(수영복)',
                '드히나' => '히나(드레스)',
                '드아루' => '아루(드레스)',
                '바토키' => '토키(바니걸)',
                '캠하레' => '하레(캠핑)',
                '운유카' => '유우카(체육복)',
                '파유카' => '유우카(파자마)',
                '뉴후카' => '후우카(새해)',
                '아마리' => '마리(아이돌)',
                '클리나' => '세리나(크리스마스)',
                '치모에' => '토모에(치파오)',
                '싸오리' => '사오리',
                '수히나' => '히나(수영복)', // 히나타(수영복)과 중복이라 규칙으로 못 푼다
            ],
        ],

        // 게임별 정보 모듈 — 레이드 페이지에서 게임 탭 선택 시 이 순서대로 섹션을 렌더한다.
        // 새 정보 유형 추가 = 프론트 모듈 컴포넌트 등록 + 여기 키 추가(게임마다 다른 구성 가능).
        //   raids: 레이드 일정·편성 카드 / attribute-parties: 속성별 추천 조합 / guides: 최근 공략글 피드
        //   event-challenges: 진행 중 이벤트 챌린지 공략(블아 — 아카 올인원 글)
        // 모듈 구성은 두 형태를 지원한다:
        //   - 평면 배열 ['raids','guides']            → 전부 메인에 세로 나열(서브탭 없음)
        //   - 구조화 ['main'=>[...], 'tabs'=>[...]]   → 메인(핀 고정) + 서브탭(미래시/학정보)
        //   신규 모듈: ongoing-content(진행중 이벤트) · pickup-banners(모집중 학생) ·
        //             future-timeline(미래시) · student-dex(학정보 도감)
        'modules' => [
            'bluearchive' => [
                'main' => ['ongoing-content', 'pickup-banners', 'raids', 'event-challenges', 'guides'],
                'tabs' => ['future-timeline', 'student-dex'],
            ],
            'nikke' => [
                'main' => ['raids', 'guides'],
                'tabs' => ['student-dex'],
            ],
            'trickcal' => [
                'main' => ['attribute-parties', 'raids', 'guides'],
                'tabs' => ['student-dex'],
            ],
            'browndust2' => [
                'main' => ['raids', 'guides'],
                'tabs' => ['student-dex'],
            ],
            // 호요버스 — 학정보(도감)만 우선. 레이드·공략 미수집이라 서브탭 없이 도감이 메인.
            'genshin' => ['student-dex'],
            'starrail' => ['student-dex'],
            'zenless' => ['student-dex'],
        ],

        /*
        | 학정보(도감) 렌더 스키마 — traits JSON 의 어떤 필드를 어떻게 보여줄지 정의.
        | growth_fields 와 같은 계약 방식(프론트 StudentDex 가 이 정의대로 동적 렌더).
        | type: stars(성급 별) · badge(코랄 pill) · text(라벨:값)
        */
        // 키는 traits JSON 의 필드명. 기존 크롤(mollulog)이 쓰는 'role'(스쿼드값) 과 겹치지 않게
        // SchaleDB 도감 필드는 tactic/squad/… 별도 키로 저장한다.
        'student_schema' => [
            'bluearchive' => [
                ['key' => 'star', 'label' => '성급', 'type' => 'stars', 'filter' => true],
                ['key' => 'tactic', 'label' => '역할', 'type' => 'badge', 'filter' => true],
                ['key' => 'squad', 'label' => '구분', 'type' => 'badge', 'filter' => true],
                ['key' => 'school', 'label' => '학교', 'type' => 'text', 'filter' => true],
                ['key' => 'weapon', 'label' => '무기', 'type' => 'text', 'filter' => false],
                ['key' => 'bullet', 'label' => '공격', 'type' => 'text', 'filter' => false],
                ['key' => 'armor', 'label' => '방어', 'type' => 'text', 'filter' => false],
                ['key' => 'position', 'label' => '위치', 'type' => 'text', 'filter' => false],
            ],
            // 니케 — traits: burst/element/weapon/manufacturer (letsdoro 크롤). 영문 코드는 labels 로 한글화.
            'nikke' => [
                ['key' => 'burst', 'label' => '버스트', 'type' => 'badge', 'filter' => true,
                    'labels' => ['STEP1' => 'Ⅰ', 'STEP2' => 'Ⅱ', 'STEP3' => 'Ⅲ', 'ALL_STEP' => 'ALL', 'ALLSTEP' => 'ALL']],
                ['key' => 'element', 'label' => '속성', 'type' => 'badge', 'filter' => true,
                    'labels' => ['FIRE' => '작열', 'WATER' => '수냉', 'WIND' => '풍압', 'IRON' => '철갑', 'ELECTRONIC' => '전격', 'ELECTRIC' => '전격', 'UTILITY' => '유틸']],
                ['key' => 'weapon', 'label' => '무기', 'type' => 'badge', 'filter' => true],
                ['key' => 'manufacturer', 'label' => '제조사', 'type' => 'text', 'filter' => true,
                    'labels' => ['ELYSION' => '엘리시온', 'MISSILIS' => '미실리스', 'TETRA' => '테트라', 'PILGRIM' => '필그림', 'ABNORMAL' => '어보노멀']],
            ],
            // 트릭컬 — traits: personality/race. rarity(3성 등)는 상위 컬럼(도감 카드가 직접 표시).
            'trickcal' => [
                ['key' => 'personality', 'label' => '성격', 'type' => 'badge', 'filter' => true,
                    'labels' => ['Gloomy' => '우울', 'Jolly' => '활발', 'Naive' => '순수', 'Cool' => '냉정', 'Mad' => '광기']],
                ['key' => 'race', 'label' => '종족', 'type' => 'badge', 'filter' => true],
            ],
            // 브더2 — 코스튬당 1행. traits: element/cd/sp/skill/base_character. rarity(5★)는 상위 컬럼.
            'browndust2' => [
                ['key' => 'element', 'label' => '속성', 'type' => 'badge', 'filter' => true,
                    'labels' => ['fire' => '불', 'water' => '물', 'wind' => '바람', 'light' => '빛', 'dark' => '어둠']],
                ['key' => 'base_character', 'label' => '원본', 'type' => 'text', 'filter' => true],
                ['key' => 'cd', 'label' => '쿨타임', 'type' => 'text', 'filter' => false],
                ['key' => 'sp', 'label' => '코스트', 'type' => 'text', 'filter' => false],
                ['key' => 'skill_name', 'label' => '스킬', 'type' => 'text', 'filter' => false],
                ['key' => 'skill_desc', 'label' => '스킬 설명', 'type' => 'text', 'filter' => false],
            ],
            // 원신 — traits: star(rank)/element/weapon/region (yatta 크롤). 영문 코드는 labels 로 한글화.
            'genshin' => [
                ['key' => 'star', 'label' => '성급', 'type' => 'stars', 'filter' => true],
                ['key' => 'element', 'label' => '속성', 'type' => 'badge', 'filter' => true,
                    'labels' => ['Ice' => '얼음', 'Fire' => '불', 'Water' => '물', 'Electric' => '번개', 'Wind' => '바람', 'Rock' => '바위', 'Grass' => '풀']],
                ['key' => 'weapon', 'label' => '무기', 'type' => 'badge', 'filter' => true,
                    'labels' => ['WEAPON_SWORD_ONE_HAND' => '한손검', 'WEAPON_CLAYMORE' => '양손검', 'WEAPON_POLE' => '창', 'WEAPON_BOW' => '활', 'WEAPON_CATALYST' => '법구']],
                ['key' => 'region', 'label' => '지역', 'type' => 'text', 'filter' => true,
                    'labels' => ['MONDSTADT' => '몬드', 'LIYUE' => '리월', 'INAZUMA' => '이나즈마', 'SUMERU' => '수메르', 'FONTAINE' => '폰타인', 'NATLAN' => '나타', 'SNEZHNAYA' => '스네주나야']],
            ],
            // 스타레일 — traits: star(rank)/path(운명경로)/element(전투속성) (yatta 소스). 내부 코드 labels 한글화.
            'starrail' => [
                ['key' => 'star', 'label' => '성급', 'type' => 'stars', 'filter' => true],
                ['key' => 'path', 'label' => '운명', 'type' => 'badge', 'filter' => true,
                    'labels' => ['Warrior' => '멸망', 'Rogue' => '수렵', 'Mage' => '지식', 'Shaman' => '화합', 'Warlock' => '공허', 'Knight' => '보존', 'Priest' => '풍요', 'Memory' => '기억', 'Elation' => '환락']],
                ['key' => 'element', 'label' => '속성', 'type' => 'badge', 'filter' => true,
                    'labels' => ['Physical' => '물리', 'Fire' => '화염', 'Ice' => '빙결', 'Thunder' => '전기', 'Wind' => '바람', 'Quantum' => '양자', 'Imaginary' => '허수']],
            ],
            // 젠레스 — traits: element(속성)/profession(특성) (Enka 소스). rarity(S/A)는 상위 컬럼 표시.
            'zenless' => [
                ['key' => 'element', 'label' => '속성', 'type' => 'badge', 'filter' => true,
                    'labels' => ['Physics' => '물리', 'Fire' => '화염', 'Ice' => '빙결', 'Elec' => '전기', 'Ether' => '에테르', 'Wind' => '바람', 'FireFrost' => '서리', 'AuricEther' => '오라에테르']],
                ['key' => 'profession', 'label' => '특성', 'type' => 'badge', 'filter' => true,
                    'labels' => ['Attack' => '강공', 'Stun' => '격파', 'Anomaly' => '이상', 'Support' => '지원', 'Defense' => '방어', 'Rupture' => '파열']],
            ],
        ],

        /*
        | SchaleDB(블아 정보 소스) — students(학정보)·config(현재/미래시 배너·이벤트).
        | 지역 매핑: 현재=Global(KR 근사), 미래시=Jp(JP 서버가 앞서는 BA 미래시 관례).
        */
        'schaledb' => [
            'base' => env('SGI_SCHALEDB_BASE', 'https://schaledb.com'),
            'lang' => 'kr',
            'region_current' => 'Global',
            'region_forecast' => 'Jp',
            'games' => ['bluearchive'],
            'timeout' => (int) env('SGI_SCHALEDB_TIMEOUT', 20),
            // 픽업 카드용 전신 일러(몰루로그와 동일 소스 baql.net collection) — {id}.webp
            'collection_image_base' => env('SGI_BA_COLLECTION_BASE', 'https://assets.baql.net/images/students/collection'),
        ],

        /*
        | 호요버스 정보 소스 — Project Yatta(=Amber). 학정보(캐릭터 도감).
        | 원신: gi.yatta.moe/api/v2/{lang}/avatar. (스타레일·젠레스는 신뢰 소스 확정 후 추가 — hakush.in 은 접근 불가)
        */
        'yatta' => [
            'lang' => 'kr',
            'timeout' => (int) env('SGI_YATTA_TIMEOUT', 20),
            // image_template 플레이스홀더: {icon}(아이콘 코드) · {id}(캐릭터 id)
            'games' => [
                'genshin' => [
                    'base' => env('SGI_YATTA_GI_BASE', 'https://gi.yatta.moe'),
                    'image_template' => 'https://gi.yatta.moe/assets/UI/{icon}.png',
                ],
                'starrail' => [
                    'base' => env('SGI_YATTA_HSR_BASE', 'https://sr.yatta.top'),
                    // HSR 초상은 Mar-7th StarRailRes(표준 에셋 저장소) — yatta 는 이미지 직접 제공 안 함
                    'image_template' => 'https://raw.githubusercontent.com/Mar-7th/StarRailRes/master/icon/character/{id}.png',
                ],
            ],
        ],

        /*
        | 젠레스 존 제로 — Enka Network 스토어 데이터(GitHub raw, 안정적). 학정보(에이전트 도감).
        | (원래 계획 hakush.in 은 dev·운영 모두 접근 불가) avatars.Name → locs.ko 로 한글명 해석.
        */
        'enka' => [
            'timeout' => (int) env('SGI_ENKA_TIMEOUT', 20),
            'games' => [
                'zenless' => [
                    'avatars_url' => env('SGI_ENKA_ZZZ_AVATARS', 'https://raw.githubusercontent.com/EnkaNetwork/API-docs/master/store/zzz/avatars.json'),
                    'locs_url' => env('SGI_ENKA_ZZZ_LOCS', 'https://raw.githubusercontent.com/EnkaNetwork/API-docs/master/store/zzz/locs.json'),
                    'lang' => 'ko',
                    'image_base' => 'https://enka.network',
                ],
            ],
        ],

        // 이벤트 챌린지 공략(블아) — 아카 채널의 이벤트 '올인원' 글에서 챌린지 섹션을 파싱.
        // 조합 표는 없고 본문 언급 + 스테이지별 유튜브 임베드가 규칙이라, 언급 캐릭터·영상을 스테이지 단위로 저장한다.
        'event_challenges' => [
            'games' => ['bluearchive'],
            // 올인원 글 검색어(제목 검색). 시리즈 글 제목이 "저장용 {이벤트명} 올인원" 형식이라
            // 두 단어가 모두 제목에 있어야 후보로 본다(잡담 오탐 방지).
            'search_keyword' => '올인원',
            'require_title_words' => ['저장용', '올인원'],
            'fetch_delay_seconds' => (float) env('SGI_EVENT_CHALLENGE_FETCH_DELAY', 1.0),
            // 보조 영상 소스 — 스테이지당 최대 저장 수(주 영상 외 관련 영상 목록)
            'max_extra_videos_per_stage' => (int) env('SGI_EVENT_CHALLENGE_MAX_VIDEOS', 3),
            // ① 유튜브 검색: "{게임 한글명} {이벤트명} 챌린지 공략" 결과에서 제목의 '챌린지 N/EX'로 스테이지 매핑
            'youtube' => [
                'enabled' => (bool) env('SGI_EVENT_CHALLENGE_YOUTUBE', true),
                'query_template' => '블루아카이브 {event} 챌린지 공략',
            ],
            // ② 디시 챌린지 글: 제목에 스테이지 번호('챌린지 3'/'챌 EX')가 있는 글의 본문 유튜브 링크를 붙인다.
            // 디시 제목 검색은 구문 일치라 단어 하나만 쓴다('챌린지 공략'은 0건).
            'dc' => [
                'enabled' => (bool) env('SGI_EVENT_CHALLENGE_DC', true),
                'search_keyword' => '챌린지',
                'max_posts' => 8,
            ],
        ],

        // 속성(성격)별 추천 조합 — 트릭컬 전용. Gemini(토큰) 없이 팀 매니저 큐레이션 크롤.
        // (트릭컬 레코드 시즌 실측은 crawl-raids 의 레이드 정보로 분리)
        'attribute_parties' => [
            'games' => ['trickcal'],
            // 성격 코드(캐릭터 traits.personality 와 동일) → 한글 라벨. 배열 순서 = 화면 탭 순서.
            'attributes' => [
                'trickcal' => [
                    'Gloomy' => '우울',
                    'Jolly' => '활발',
                    'Naive' => '순수',
                    'Cool' => '냉정',
                    'Mad' => '광기',
                ],
            ],
        ],

        // 공략글 수집(디씨 개념글 · 아카 추천글) — 메타만 저장, 본문 파싱 없음.
        'guides' => [
            'max_posts_per_source' => (int) env('SGI_GUIDE_MAX_POSTS', 30),
            'keep_days' => (int) env('SGI_GUIDE_KEEP_DAYS', 60),
            // 개념글/추천글 대부분은 팬아트·유머라, 제목에 아래 키워드(또는 보스명·레이드 키워드)가
            // 있는 글만 공략으로 인정해 저장한다.
            'title_keywords' => ['공략', '편성', '조합', '티어', '육성', '가이드', '세팅', '파티', '클리어', '스펙', '덱', '점수', '스코어', '점수컷', '컷트라인', '무과금'],
            // 보스명 제목 검색 접미사 — "{보스명} {접미사}"(예: "비나 공략", "비나 대체")로 디씨·아카를
            // 제목 검색해 개념글·추천글에 잘 안 올라오는 레이드 공략·대체 캐릭터 글을 보강 수집한다.
            'search_suffixes' => ['공략', '대체'],
            // 잡담 걸러내기 — 제목에 아래 표현이 있으면(또는 '?'로 끝나는 질문글이면) 공략으로 안 본다.
            // 검색 수집은 목록 수집보다 잡담 유입이 많아 필수.
            // '대체 뭐/왜/…'는 부사(도대체) 용법 — "비나 대체" 검색이 끌고 오는 잡담을 거른다.
            'exclude_title_keywords' => [
                'ㅋㅋ', 'ㅎㅎ', 'ㅠㅠ', 'ㅜㅜ', '시발', '씨발', '싯팔', 'ㅅㅂ', '존나', '웃기', '개빵',
                '도대체', '대체 뭐', '대체 왜', '대체 누구', '대체 어떻', '대체 무슨',
                '대체 이 ', '대체 그 ', '대체 저 ', '대체 정체',
            ],
            // 레이드 매칭 보조 키워드(보스명 외 게임별 통칭). 제목 매칭은 boss_name + 아래 키워드.
            'raid_keywords' => [
                'bluearchive' => ['총력전', '대결전', '제약해제결전'],
                'nikke' => ['솔로 레이드', '솔레', '유니온 레이드', '이상 개체'],
                'trickcal' => ['프론티어', '차원 대충돌', '레이드'],
                'browndust2' => ['길드 레이드', '악마성', '레이드'],
            ],
        ],

        // 대체 캐릭터 추출 — 공략글 본문을 가져와(Gemini) "A가 없으면 B로 대체" 관계를 뽑는다.
        // body_selectors 는 소스별 본문 영역 CSS 셀렉터(더쿠/루리웹은 조사 후 추가 예정).
        'substitutes' => [
            'body_selectors' => [
                'dc' => '.write_div',
                'arca' => '.article-content',
            ],
            // Gemini 에 전달하는 합산 본문 최대 길이(프롬프트 비용·컨텍스트 상한)
            'max_body_chars' => (int) env('SGI_SUB_MAX_BODY_CHARS', 20000),
            // 상위 캐릭터 1명당 저장하는 대체 캐릭터 수 상한
            'max_substitutes_per_character' => (int) env('SGI_SUB_MAX_PER_CHARACTER', 4),
            // 레이드당 본문을 가져올 공략글 수 상한(최신순) — DC/아카 대량 요청·Gemini 비용 방지
            'max_posts_per_raid' => (int) env('SGI_SUB_MAX_POSTS_PER_RAID', 6),
            // 종료된 레이드도 이 일수 안이면 추출 대상에 포함 — 종료 직후에도 대체 정보는
            // 다음 회차·미보유 사용자에게 유효하다(0이면 진행 중·예정만)
            'include_ended_days' => (int) env('SGI_SUB_INCLUDE_ENDED_DAYS', 14),
            // 본문 요청 간 딜레이(초) — 커뮤니티 차단 방지(다른 크롤러의 crawl_delay 와 동일 원칙)
            'fetch_delay_seconds' => (float) env('SGI_SUB_FETCH_DELAY', 1.0),
        ],

        // 미보유 캐릭터 제외 실전 편성 — 원본 랭킹 사이트 프록시(블아=몰루로그, 니케=레츠도로).
        'alternative_parties' => [
            'per_page' => (int) env('SGI_ALT_PARTY_PER_PAGE', 5),
            // 니케 랭커 단위 필터 결과가 이 수 미만이면 스쿼드 단위 필터로 보강한다
            'min_ranker_results' => 3,
            'timeout' => 10,
            'mollulog' => [
                'graphql_endpoint' => 'https://api.baql.net/graphql',
                'ranks_endpoint' => 'https://ranks.baql.net/v1/ranks',
                'stats_endpoint' => 'https://ranks.baql.net/v1/stats', // 학생별 출전 통계(protobuf)
                'schedule_cache_ttl' => 86400, // 시즌 매핑(GraphQL) — 일정은 거의 안 바뀌므로 1일
                'ranks_cache_ttl' => 3600,     // 랭킹(protobuf) — CloudFront 엣지 캐시(24h)와 별개로 우리 쪽 1시간
            ],
            'letsdoro' => [
                'endpoint' => 'https://api3.letsdoro.com/api/soloraid',
                'server' => 'KR',
                'cache_ttl' => 1800, // 원본 응답이 no-cache 라 우리 쪽 캐시 필수(30분)
            ],
        ],
    ],
];
