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
        'strip_patterns' => [
            '/\[.*?\]/u',
            '/\d{2,4}년\s*\d{1,2}월\s*발매/u',
            '/재판/u',
            '/예약/u',
            '/특전.*?포함/u',
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
