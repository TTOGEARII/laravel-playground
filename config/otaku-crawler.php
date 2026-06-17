<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 크롤링 대상 샵 정의 (otaku_shop insert 후 ok_shop_code 로 매칭)
    |--------------------------------------------------------------------------
    */
    'shops' => [
        [
            'ok_shop_code' => 'dokidokigoods',
            'ok_shop_name' => '도키도키굿즈',
            'ok_shop_url' => 'https://dokidokigoods.co.kr/',
        ],
        [
            'ok_shop_code' => 'animate',
            'ok_shop_name' => '애니메이트코리아 온라인샵',
            'ok_shop_url' => 'https://www.animate-onlineshop.co.kr/',
        ],
        [
            'ok_shop_code' => 'ttabbaemall',
            'ok_shop_name' => '따빼몰',
            'ok_shop_url' => 'https://ttabbaemall.co.kr/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 공통 카테고리 (otaku_category insert용)
    |--------------------------------------------------------------------------
    */
    'categories' => [
        ['ok_category_code' => 'figure', 'ok_category_label' => '피규어', 'ok_category_sort' => 10],
        ['ok_category_code' => 'plush', 'ok_category_label' => '봉제인형', 'ok_category_sort' => 20],
        ['ok_category_code' => 'goods', 'ok_category_label' => '굿즈', 'ok_category_sort' => 30],
        ['ok_category_code' => 'accesory', 'ok_category_label' => '액세서리/키링', 'ok_category_sort' => 40],
        ['ok_category_code' => 'book', 'ok_category_label' => '서적', 'ok_category_sort' => 50],
        ['ok_category_code' => 'other', 'ok_category_label' => '기타', 'ok_category_sort' => 99],
    ],

    /*
    |--------------------------------------------------------------------------
    | 샵별 크롤 대상 리스트 페이지 (카테고리 단위)
    |--------------------------------------------------------------------------
    | path     : ok_shop_url 기준 상대 경로 (카테고리/리스트 페이지)
    | category : 해당 페이지 상품의 기본 공통 카테고리 코드(categories 의 ok_category_code).
    |            제목 키워드로 더 정확히 추정되면 그 값으로 보정된다.
    | pages    : 수집할 페이지 수(페이지네이션, ?page=N). 트래픽 완화를 위해 보수적으로.
    |
    | - 도키도키굿즈/따빼몰 : cafe24 (product/list.html?cate_no=)
    | - 애니메이트          : 고도몰 (goods/goods_list.php?cateCd=, 제목 【카테고리】 접두사)
    */
    'listings' => [
        'dokidokigoods' => [
            ['path' => '/product/list.html?cate_no=287', 'category' => 'figure', 'pages' => 2],
            ['path' => '/product/list.html?cate_no=28', 'category' => 'goods', 'pages' => 2],
            ['path' => '/product/list.html?cate_no=65', 'category' => 'other', 'pages' => 1],
        ],
        'animate' => [
            ['path' => '/goods/goods_list.php?cateCd=008', 'category' => 'goods', 'pages' => 2],
            ['path' => '/goods/goods_list.php?cateCd=010', 'category' => 'goods', 'pages' => 2],
        ],
        'ttabbaemall' => [
            ['path' => '/product/list.html?cate_no=29', 'category' => 'figure', 'pages' => 2],
            ['path' => '/product/list.html?cate_no=30', 'category' => 'goods', 'pages' => 2],
            ['path' => '/product/list.html?cate_no=195', 'category' => 'plush', 'pages' => 1],
            ['path' => '/product/list.html?cate_no=193', 'category' => 'accesory', 'pages' => 1],
            ['path' => '/product/list.html?cate_no=306', 'category' => 'accesory', 'pages' => 1],
            ['path' => '/product/list.html?cate_no=145', 'category' => 'book', 'pages' => 1],
            ['path' => '/product/list.html?cate_no=24', 'category' => 'goods', 'pages' => 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 제목 키워드 → 공통 카테고리 보정 맵 (위에서부터 먼저 매칭되는 것 사용)
    |--------------------------------------------------------------------------
    */
    'category_keywords' => [
        'figure' => ['피규어', '넨도로이드', '넨도', '프라모델', '프라이즈', '스케일', 'figure', 'figma', '피그마'],
        'plush' => ['봉제', '인형', '플러시', '마스코트', '쿠션'],
        'accesory' => ['아크릴', '키링', '키홀더', '열쇠고리', '뱃지', '뱃지', '스탠드', '스트랩', '참', '아크스'],
        'book' => ['서적', '도서', '코믹스', '만화', '화집', '색지', '일러스트', '음반', 'ost', '음악', '소설', '잡지'],
        'goods' => ['굿즈', '문구', '팬시'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Selenium / Chrome 옵션
    |--------------------------------------------------------------------------
    | 기본값은 로컬 ChromeDriver(http://localhost:9515)이며,
    | 이 프로젝트에서는 compose.yaml 에 selenium 서비스가 있으므로
    | .env 의 OTAKU_SELENIUM_URL=http://selenium:4444 로 컨테이너(Selenium)를 사용합니다.
    */
    'selenium' => [
        'driver_url' => env('OTAKU_SELENIUM_URL', 'http://localhost:9515'),
        'headless' => env('OTAKU_CRAWL_HEADLESS', true),
        'page_load_timeout_sec' => (int) env('OTAKU_PAGE_LOAD_TIMEOUT', 30),
        'implicit_wait_sec' => (int) env('OTAKU_IMPLICIT_WAIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | 상품 매칭용 정규화 (동일 상품 그룹핑)
    |--------------------------------------------------------------------------
    */
    'product_match' => [
        'title_min_length' => 5,
        // 매칭 키 생성 전에 제거할 노이즈(에디션·발매정보·카테고리 말머리 등).
        // 동일 상품을 가리키는데 쇼핑몰마다 다르게 붙는 수식어를 걷어내 매칭률을 높인다.
        // 주의: 상품을 구분하는 핵심어(캐릭터명 등)는 지우지 않도록 보수적으로 유지.
        'strip_patterns' => [
            '/【.*?】/u',                       // 【굿즈】【국내서적】 등 말머리
            '/\[.*?\]/u',                       // [예약] [특전] [27년 02월 발매] 등
            '/\d{2,4}\s*년\s*\d{1,2}\s*월\s*발매/u',
            '/특전.*?포함/u',
            '/(예약구매|예약상품|예약판매|예약|재입고|재판|신상품|정식발매|국내정식)/u',
        ],
        // IP(작품)명 별칭 통일: 띄어쓰기·줄임말 표기가 쇼핑몰마다 달라 매칭을 방해하므로
        // 좌측 표준 토큰으로 합친다. (예: "블루 아카이브"/"블아" → "블루아카이브")
        // 캐릭터명·라인명은 건드리지 않으므로 서로 다른 상품이 잘못 묶일 위험은 없다.
        'ip_aliases' => [
            '블루아카이브' => ['블루 아카이브', '블아'],
            '붕괴스타레일' => ['붕괴 스타레일', '붕스'],
            '명일방주' => ['명방'],
            '페이트스테이나이트' => ['페이트 스테이 나이트', '페스나'],
            '페이트그랜드오더' => ['페이트 그랜드 오더', '페그오', 'fgo'],
            '귀멸의칼날' => ['귀멸의 칼날', '귀칼'],
            '하츠네미쿠' => ['하츠네 미쿠'],
            '프로젝트세카이' => ['프로젝트 세카이', '프로세카'],
            '주술회전' => ['주술 회전', '주회'],
            '최애의아이' => ['최애의 아이', '오시노코'],
            '진격의거인' => ['진격의 거인'],
            '스파이패밀리' => ['스파이 패밀리', '스파이x패밀리', '스파이 x 패밀리', '스파이파밀리'],
            '체인소맨' => ['체인소 맨', '체인소우맨', '체인소우 맨'],
            '러브라이브' => ['러브 라이브'],
            '리코리스리코일' => ['리코리스 리코일', '리코리코'],
            '우마무스메' => ['우마 무스메'],
            '그랑블루판타지' => ['그랑블루 판타지', '그랑블루', '그블'],
            '아이돌마스터' => ['아이돌 마스터', '아이마스'],
            '봇치더록' => ['봇치 더 록', '봇치더락', '봇치 더 락'],
            '원피스' => ['원 피스'],
            '약사의혼잣말' => ['약사의 혼잣말'],
            '카드캡터사쿠라' => ['카드캡터 사쿠라', '카캡사'],
        ],
        // 매칭 시그니처에서 제외할 토큰(제조사/일반 명사). 쇼핑몰마다 붙이거나 빼는 수식어라
        // 동일 상품 매칭을 방해하므로 빼고, 남은 변별 토큰(IP명·캐릭터명·라인명)으로 매칭한다.
        // 단독 숫자(넨도 번호/발매연도)와 1글자 토큰도 자동 제외된다.
        'match_stopwords' => [
            // 제조사/브랜드
            '굿스마일', '굿스마일컴퍼니', '굿스', '컴퍼니', '메가하우스', '반프레스토', '세가',
            '후류', '코토부키야', '알터', '맥스팩토리', '아츠', '상하이', '타이토', '메디콤',
            '굿즈컴퍼니', '에이펙스', '반다이', '아니플렉스', '아니플렉스플러스',
            '리보세', 'ribose', '프리잉', 'freeing', 'fnex', '호비맥스', 'hobbymax',
            '유니온크리에이티브', '그리폰', '다이키', '펄세일', '굿스마일아츠', '메디코스',
            '코토부키야', '벨파인', '아조네', '플레어', '세가프라이즈', '럭키캣',
            // 줄임말(국내 쇼핑몰이 본명과 함께 덧붙이는 경우)
            '블아', '붕스', '명방', '원신굿즈',
            // 일반 명사/수식어
            '피규어', '굿즈', '상품', '정품', '한정', '한정판', '신극장판', '버전', 'ver',
            '스케일', '프라이즈', '경품', '세트', '공식', '국내', '해외', '예약',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 크롤 딜레이 설정 (트래픽 완화용)
    |--------------------------------------------------------------------------
    | delay_ms_between_requests : 한 사이트 내에서 페이지 이동 사이의 대기(ms)
    | delay_ms_between_shops    : 각 쇼핑몰 크롤 사이의 대기(ms)
    */
    'crawl' => [
        'delay_ms_between_requests' => (int) env('OTAKU_CRAWL_DELAY_MS', 1500),
        'delay_ms_between_shops' => (int) env('OTAKU_CRAWL_DELAY_BETWEEN_SHOPS_MS', 2000),
    ],
];
