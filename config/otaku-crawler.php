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
        [
            'ok_shop_code' => 'goodsmilekr',
            'ok_shop_name' => '굿스마일코리아',
            'ok_shop_url' => 'https://brand.naver.com/goodsmilekr',
        ],
        [
            'ok_shop_code' => 'comicsart',
            'ok_shop_name' => '코믹스아트',
            'ok_shop_url' => 'https://comics-art.co.kr',
        ],
        [
            'ok_shop_code' => 'figurepresso',
            'ok_shop_name' => '피규어프레소',
            'ok_shop_url' => 'https://figurepresso.com',
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
        // 굿스마일 네이버 브랜드스토어. category/<해시> 단위, 페이지네이션이 JS 클릭식이라
        // 카테고리별 최신 1페이지(40개)만 수집한다. category 는 제목 키워드로 보정되며 fallback 으로 둔다.
        'goodsmilekr' => [
            ['path' => 'category/4c33be6ce4f44c339ed824cdcfc50d20', 'category' => 'figure', 'pages' => 1], // 넨도로이드
            ['path' => 'category/2e9567ebfd264392b694fa367e9929c6', 'category' => 'figure', 'pages' => 1], // 피그마
            ['path' => 'category/2a0e44cbcc0443c5aaaf7a49d2d08f73', 'category' => 'figure', 'pages' => 1], // POP UP PARADE
            ['path' => 'category/ac2d3e7c51bd4725b756d5d39353cd96', 'category' => 'figure', 'pages' => 1], // 피규어
            ['path' => 'category/576f470eafa14e6ea5ee02f3c63a82f1', 'category' => 'figure', 'pages' => 1], // 프라모델
            ['path' => 'category/32670fa45d6a420a829dc19e8f1a30ae', 'category' => 'plush', 'pages' => 1],  // 인형
            ['path' => 'category/294cc84fabf0471db16bcbae5ba43827', 'category' => 'goods', 'pages' => 1],  // 그외상품
        ],
        // 코믹스아트 (cafe24 표준 스킨). 신작·입고 카테고리 위주, 전량크롤은 cafe24 카테고리 자동발견.
        'comicsart' => [
            ['path' => '/product/list.html?cate_no=3132', 'category' => 'figure', 'pages' => 2], // 신작/당일입고
            ['path' => '/product/list.html?cate_no=1215', 'category' => 'figure', 'pages' => 2], // 신작상품
            ['path' => '/product/list.html?cate_no=49', 'category' => 'figure', 'pages' => 2],   // 입고완료/당일발송
        ],
        // 피규어프레소 (cafe24 SEO URL 스킨). 입고상품 + 제조사별(listmaker) 리스트.
        // 전량크롤은 FigurePressoCrawler 가 list/listmaker/preorder/listgoods 를 자동 발견한다.
        'figurepresso' => [
            ['path' => '/product/list.html?cate_no=25', 'category' => 'figure', 'pages' => 3],       // 입고상품
            ['path' => '/product/listmaker.html?cate_no=1671', 'category' => 'figure', 'pages' => 2], // 제조사별(신상)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 수집 제외 키워드 (제목에 포함되면 그 상품은 적재하지 않음)
    |--------------------------------------------------------------------------
    | 분할결제 전용 listing(실제 상품가가 아닌 계약금/잔금만 결제하는 항목)은 비교 대상이 아니다.
    | 또 같은 상품이 'OOO (예약금결제)' / 'OOO' 두 건으로 올라와 다른 상품으로 갈리는 걸 막는다.
    | - '잔금결제'   : 예약 잔금 결제 전용
    | - '예약금결제' : 예약 계약금(부분) 결제 전용
    */
    'exclude_title_keywords' => ['잔금결제', '예약금결제'],

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
        // 'eager' = DOMContentLoaded 까지만 기다리고(이미지·트래커 등 서브리소스 대기 안 함) 진행.
        // 상품은 DOM 에서 읽으므로 충분하고, 멈춘 서브리소스로 인한 렌더러 타임아웃을 크게 줄인다.
        'page_load_strategy' => env('OTAKU_PAGE_LOAD_STRATEGY', 'eager'),
        // WebDriver HTTP(curl) 타임아웃. 없으면(0=무한) 브라우저가 응답 안 주는 페이지에서
        // 명령 하나가 영원히 블록돼 크롤 전체가 멈춘다(실제 발생). request 는 page_load 보다
        // 커서 정상 페이지를 안 끊되, 진짜 hang 은 잘라 try/catch 로 흘려보낸다.
        'connection_timeout_sec' => (int) env('OTAKU_CONNECTION_TIMEOUT', 30),
        'request_timeout_sec' => (int) env('OTAKU_REQUEST_TIMEOUT', 60),
        // 크롤 시 항상 실제 브라우저 UA로 요청한다(헤드리스/봇 차단 회피). 빈 값이면 CrawlerDriver 기본 UA 사용.
        'user_agent' => env('OTAKU_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 상품 매칭용 정규화 (동일 상품 그룹핑)
    |--------------------------------------------------------------------------
    */
    'product_match' => [
        'title_min_length' => 5,
        // 상품 고유값(제조사 품번/모델 번호) 추출 패턴.
        // 제목에서 결정적으로 뽑히는 값이라 쇼핑몰이 달라도 같은 코드가 나와, 동일상품 매칭의 강한 키가 된다.
        // 키=코드 접두사, 값=정규식(첫 캡처그룹이 번호). 위에서부터 먼저 매칭되는 것을 쓴다.
        'maker_code_patterns' => [
            // 번호 앞 표기(#, No., №, 넘버)는 쇼핑몰마다 달라 옵션 그룹으로 흡수한다.
            'jan' => '/(?<![0-9])(\d{13})(?![0-9])/u',                    // JAN/EAN-13 바코드
            'nendo' => '/넨도로이드(?:\s*doll)?\s*(?:#|no\.?|№|넘버)?\s*(\d{2,5})/iu',        // 넨도로이드 번호
            'figma' => '/(?:figma|피그마)\s*(?:#|no\.?|№|넘버)?\s*(\d{1,4})/iu',            // figma 번호
            'figuarts' => '/(?:s\.?h\.?\s*)?(?:피규어츠|figuarts)\s*(?:#|no\.?|№|넘버)?\s*(\d{1,4})/iu', // 피규어츠 번호 (바 "츠" 오탐 방지: 전체 표기 요구)
            'popup' => '/(?:팝업\s*퍼레이드|pop\s*up\s*parade)\s*(?:#|no\.?|№|넘버)?\s*(\d{1,4})/iu',
        ],
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
            // --- 2026-06-18 제목 빈도 채굴로 보강한 IP들 (커버리지 확대) ---
            '나의히어로아카데미아' => ['나의 히어로 아카데미아', '나의 히어로', '히로아카', '나히아', '비질란테', '비질랜티'],
            '윈드브레이커' => ['윈드 브레이커', '윈브레', 'wind breaker', 'windbreaker'],
            '승리의여신니케' => ['승리의 여신', '니케', '승니', 'nikke'],
            '도원암귀' => ['도원 암귀'],
            '괴수8호' => ['괴수 8호', 'kaiju no.8'],
            '원신' => ['원신 임팩트', 'genshin'],
            '명조' => ['명조 워더링웨이브', '명조 공명자', 'wuthering waves'],
            '블루록' => ['블루 록', '블루락', 'blue lock'],
            '카구야님' => ['초 가구야', '카구야 님', '카구야님은', '가구야님'],
            '젠레스존제로' => ['젠레스 존 제로', '젠레스 존', '젠존제', 'zenless zone zero'],
            '가치아쿠타' => ['가치 아쿠타'],
            '에일리언스테이지' => ['에일리언 스테이지', 'alien stage'],
            '명탐정코난' => ['명탐정 코난', '코난'],
            '사카모토데이즈' => ['사카모토 데이즈', 'sakamoto days'],
            '뱅드림' => ['뱅 드림', 'bang dream', '걸즈밴드파티'],
            '도쿄리벤저스' => ['도쿄 리벤저스', '도리벤', 'tokyo revengers'],
            '뉴카니발' => ['nu: 카니발', 'nu카니발', '뉴 카니발', 'nu carnival'],
            '포켓몬' => ['포켓몬스터', '포켓몬 마스터즈', 'pokemon'],
            '은혼' => ['gintama'],
            '림버스컴퍼니' => ['limbus company', '림버스', '프로젝트 문'],
            '나루토' => ['나루토 질풍전', '보루토', 'naruto', 'boruto'],
            '장송의프리렌' => ['장송의 프리렌', '프리렌', 'frieren'],
            '그비스크돌' => ['그 비스크', '비스크 돌', 'sono bisque'],
            '리버스1999' => ['리버스 1999', 'reverse 1999'],
            '하이큐' => ['하이큐!!', 'haikyu'],
            '단간론파' => ['단간 론파', 'danganronpa'],
            '문호스트레이독스' => ['문호 스트레이독스', '문스독', 'bungo stray dogs'],
            '가정교사히트맨리본' => ['가정교사 히트맨', '히트맨 리본', '카테쿄'],
            '퍼니싱그레이레이븐' => ['퍼니싱 그레이', 'punishing gray raven'],
            '걸즈밴드크라이' => ['걸즈 밴드 크라이', 'girls band cry', '걸밴크'],
            '마법소녀마도카마기카' => ['마법소녀 마도카', '마도카 마기카', '마도카', 'madoka'],
            '해즈빈호텔' => ['헤이즈빈 호텔', '해즈빈 호텔', 'hazbin hotel'],
            '마계학교이루마군' => ['마계학교 이루마', '이루마군', '이루마 군'],
            '데이트어라이브' => ['데이트 어 라이브', 'date a live'],
            '리제로' => ['re:제로', 're 제로', 're zero', '리 제로'],
            '소드아트온라인' => ['소드 아트 온라인', '소아온', 'sword art online'],
            '에반게리온' => ['신세기 에반게리온', 'evangelion', '에바'],
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
    |
    | full : 전량 크롤(otaku-shop:crawl-full, 최초 1회)용 설정.
    |   차단 방지를 위해 더 긴 딜레이를 쓰고, max_pages 까지(빈 페이지 만나면 자동 중단)
    |   카테고리별로 끝 페이지까지 수집한다.
    */
    'crawl' => [
        'delay_ms_between_requests' => (int) env('OTAKU_CRAWL_DELAY_MS', 1500),
        'delay_ms_between_shops' => (int) env('OTAKU_CRAWL_DELAY_BETWEEN_SHOPS_MS', 2000),
        // 한 샵 안에서 이만큼 페이지를 로드하면 브라우저 세션을 새로 만든다(0=비활성).
        // 단일 세션을 수천 페이지·수 시간 재사용하면 렌더러가 불안정해져 간헐 타임아웃이 나므로,
        // 주기적으로 세션을 갈아 degradation 을 막는다.
        'recycle_after_pages' => (int) env('OTAKU_RECYCLE_AFTER_PAGES', 80),
        // 상세 페이지 추가 크롤(옵트인). 켜면 리스트 수집 후 각 상품 상세를 한 번 더 열어
        // 바코드(JAN/자체상품코드=고유값)·제조사·품절을 보강한다. 상품마다 요청이 1건씩 더 늘어
        // 크롤 시간이 크게 증가하므로 기본은 끔. 필요 시 .env 에서 켠다.
        'fetch_detail' => (bool) env('OTAKU_CRAWL_FETCH_DETAIL', false),
        // 전역 flag 없이도 상세 보강을 켤 샵 목록. 애니메이트는 리스트에 고유값이 없고
        // 상세의 '자체상품코드'(바코드)만이 동일상품 매칭에 쓸 신뢰값이라 여기에 둔다.
        // (cafe24는 상세에 추가 정보가 없고, 굿스마일은 리스트 JSON API로 이미 다 받으므로 불필요.)
        'fetch_detail_shops' => ['animate'],
        'detail' => [
            'delay_ms' => (int) env('OTAKU_CRAWL_DETAIL_DELAY_MS', 1200),
            // 샵당 상세를 더 볼 최대 상품 수(0=제한 없음). 부분 보강·테스트용 안전장치.
            'max_products' => (int) env('OTAKU_CRAWL_DETAIL_MAX', 0),
        ],
        'full' => [
            'delay_ms_between_requests' => (int) env('OTAKU_FULL_DELAY_MS', 3000),
            'delay_ms_between_shops' => (int) env('OTAKU_FULL_DELAY_BETWEEN_SHOPS_MS', 8000),
            'max_pages' => (int) env('OTAKU_FULL_MAX_PAGES', 100),
        ],
        // 전량 크롤의 '사라짐=품절' 대상에서 제외할 샵. 카테고리 전체를 돌지 않고(부분 수집)
        // 품절을 리스트 배지로 직접 읽는 샵은, 안 보였다고 품절 처리하면 오탐이 난다.
        // (굿스마일: 페이지네이션이 JS 클릭식이라 카테고리별 1페이지만 수집)
        'no_disappear_soldout_shops' => ['goodsmilekr'],
    ],
];
