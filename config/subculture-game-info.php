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

        // 속성(성격)별 추천 조합 — 트릭컬 전용. Gemini(토큰) 없이 구조화 사이트 크롤만 사용:
        // 팀 매니저(큐레이션) + 트릭컬 레코드(시즌 실측 → traits.personality 로 속성별 파생).
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
            // 실측(usage) 파생 시 속성·포지션당 상위 N명(사용률순)
            'usage_top_per_position' => (int) env('SGI_ATTR_USAGE_TOP', 4),
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
