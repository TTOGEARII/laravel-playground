<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 각 컬럼(column-level)에 한국어 설명(COMMENT)을 채운다.
 *
 * MySQL 8 은 "코멘트만" 바꾸는 DDL 이 없어 MODIFY COLUMN 으로 타입 전체를 다시 지정해야 한다.
 * 타입을 손으로 재작성하면 스키마가 미묘하게 바뀔 위험이 있어(collation·기본값·auto_increment 등),
 * information_schema 에서 각 컬럼의 실제 정의를 읽어 그대로 보존한 채 COMMENT 만 갈아끼운다.
 * 프레임워크 기본 테이블(migrations/sessions/cache/jobs 등)은 대상에서 제외.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> 테이블 => (컬럼 => 설명) */
    private array $comments = [
        // ── 공통 ──────────────────────────────────────────────
        'users' => [
            'id' => '기본 키',
            'name' => '회원명(닉네임)',
            'email' => '이메일(소셜 전용 계정은 null)',
            'email_verified_at' => '이메일 인증 시각',
            'password' => '비밀번호 해시(소셜 전용 계정은 null)',
            'remember_token' => '자동 로그인 토큰',
            'created_at' => '가입 일시',
            'updated_at' => '수정 일시',
        ],
        'social_accounts' => [
            'id' => '기본 키',
            'user_id' => '연동 회원 ID(users.id)',
            'provider' => '소셜 제공자(kakao/google 등)',
            'provider_user_id' => '제공자 측 사용자 고유 ID',
            'nickname' => '제공자 프로필 닉네임',
            'profile_image' => '제공자 프로필 이미지 URL',
            'access_token' => 'OAuth 액세스 토큰',
            'refresh_token' => 'OAuth 리프레시 토큰',
            'token_expires_at' => '액세스 토큰 만료 시각',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'push_subscriptions' => [
            'id' => '기본 키',
            'user_id' => '구독 회원 ID(비로그인 null)',
            'endpoint' => '푸시 서비스 엔드포인트 URL',
            'p256dh_key' => '웹푸시 공개키(P-256 ECDH)',
            'auth_key' => '웹푸시 인증 시크릿',
            'endpoint_hash' => 'sha256(endpoint) — 중복 구독 판별 키',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'inquiries' => [
            'id' => '기본 키',
            'category' => '문의 유형(general/bug/feature)',
            'name' => '작성자 이름/닉네임',
            'contact' => '답변받을 연락처(이메일/디스코드 등, 선택)',
            'subject' => '제목',
            'message' => '문의 내용',
            'status' => '처리 상태(received/in_progress/resolved)',
            'user_id' => '작성 회원 ID(비로그인 null)',
            'ip_address' => '작성자 IP(스팸 차단용, IPv6 대응)',
            'created_at' => '접수 일시',
            'updated_at' => '수정 일시',
        ],
        'access_logs' => [
            'id' => '기본 키',
            'ip' => '실제 클라이언트 IP(신뢰 프록시 뒤 X-Forwarded-For)',
            'device' => '기기 구분(pc/mobile/tablet/bot, UA 정규식 판별)',
            'method' => 'HTTP 메서드(기록 대상은 GET 페이지뷰)',
            'path' => '방문 경로(쿼리스트링 포함)',
            'referrer' => '유입경로 — 직전 페이지 URL(외부 유입 판별)',
            'user_agent' => '브라우저 User-Agent 원문',
            'user_id' => '로그인 사용자면 users.id, 비로그인은 null',
            'created_at' => '접속(방문) 일시',
        ],

        // ── OtakuShop ─────────────────────────────────────────
        'otaku_shop' => [
            'ok_shop_id' => '샵 PK',
            'ok_shop_code' => '샵 코드(내부 식별용)',
            'ok_shop_name' => '샵 이름(표시용)',
            'ok_shop_url' => '샵 기본 URL',
            'ok_shop_active_flg' => '사용 여부(1:사용, 0:미사용)',
            'create_dt' => '생성 일시',
            'update_dt' => '수정 일시',
        ],
        'otaku_category' => [
            'ok_category_id' => '카테고리 PK',
            'ok_category_code' => '카테고리 코드(내부 식별용)',
            'ok_category_label' => '카테고리 표시 이름',
            'ok_category_sort' => '정렬 순서',
            'create_dt' => '생성 일시',
            'update_dt' => '수정 일시',
        ],
        'otaku_ip' => [
            'ok_ip_id' => 'IP(작품) PK',
            'ok_ip_code' => 'IP 코드(정규화 표준 토큰, 내부 식별용)',
            'ok_ip_label' => 'IP 표시 이름',
            'ok_ip_sort' => '정렬 순서',
            'create_dt' => '생성 일시',
            'update_dt' => '수정 일시',
        ],
        'otaku_product' => [
            'ok_product_id' => '상품 PK',
            'ok_product_code' => '상품 코드(내부 식별용)',
            'ok_product_title' => '상품 제목',
            'ok_product_subtitle' => '상품 서브 제목/설명',
            'ok_product_brand_label' => '브랜드/레이블 이름',
            'ok_product_maker_code' => '상품 고유값(JAN 바코드/제조사 품번) — 쇼핑몰 간 동일상품 매칭 키',
            'ok_product_maker_name' => '제조사명(상세 크롤로 보강, 예: 굿스마일 컴퍼니)',
            'ok_product_match_sig' => '이름 유사 매칭용 정규화 시그니처(정렬 토큰)',
            'ok_product_release_date' => '발매일',
            'ok_product_active_flg' => '노출 여부(1:노출, 0:숨김)',
            'ok_product_cate_id' => '카테고리 ID(otaku_category.ok_category_id)',
            'ok_product_ip_id' => 'IP(작품) ID(otaku_ip.ok_ip_id)',
            'ok_product_image_url' => '대표 이미지 URL(외부)',
            'ok_product_image_hash' => '이미지 dHash(hex16) — 빈값=해시 실패',
            'create_dt' => '생성 일시',
            'update_dt' => '수정 일시',
        ],
        'otaku_offer' => [
            'ok_offer_id' => '오퍼 PK',
            'ok_offer_product_id' => '상품 ID(otaku_product.ok_product_id)',
            'ok_offer_shop_id' => '샵 ID(otaku_shop.ok_shop_id)',
            'ok_offer_currency' => '통화 코드(JPY/KRW 등)',
            'ok_offer_price' => '기준 통화 가격',
            'ok_offer_local_price' => '환산된 로컬 통화 가격',
            'ok_offer_shipping_fee' => '배송비',
            'ok_offer_lowest_flg' => '상품별 최저가 여부 플래그',
            'ok_offer_available_flg' => '판매 가능 여부',
            'ok_offer_external_url' => '외부 샵 상품 상세 URL',
            'ok_offer_external_id' => '샵 내부 상품 ID 또는 URL 해시(오퍼 동일성·사라짐 매칭 키)',
            'ok_offer_collected_dt' => '가격 수집/동기화 시각',
            'create_dt' => '생성 일시',
            'update_dt' => '수정 일시',
        ],
        'otaku_wish' => [
            'ok_wish_id' => '찜 PK',
            'user_id' => '회원 ID(users.id)',
            'ok_wish_product_id' => '상품 ID(otaku_product.ok_product_id)',
            'create_dt' => '생성 일시',
            'update_dt' => '수정 일시',
        ],

        // ── MyWifeBot ─────────────────────────────────────────
        'chat_characters' => [
            'id' => '기본 키',
            'user_id' => '캐릭터 생성 회원 ID(users.id)',
            'name' => '캐릭터 이름',
            'short_intro' => '한 줄 소개',
            'character_detail' => '캐릭터 상세',
            'personality' => '성격(페르소나)',
            'appearance' => '외모 묘사',
            'likes' => '좋아하는 것',
            'dislikes' => '싫어하는 것',
            'user_alias' => '캐릭터가 유저를 부르는 호칭',
            'example_dialogue' => '예시 대화(few-shot)',
            'world_setting' => '소설/세계관 배경 설정',
            'relationships' => '세계관 속 주요 인물과의 관계(자유 서술)',
            'user_persona' => '대화 상대(유저)의 기본 페르소나(자유 서술)',
            'speech_style' => '말투 설정',
            'intro_message' => '캐릭터 첫 인사(Gemini 생성)',
            'genre' => '장르(romance/fantasy/action/slice_of_life/otaku)',
            'target' => '타겟(all/male/female/teen)',
            'image_path' => '캐릭터 이미지 경로(storage 기준)',
            'accent' => '카드 강조색 클래스',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'chat_sessions' => [
            'id' => '기본 키',
            'user_id' => '대화한 회원 ID(비회원 null)',
            'chat_character_id' => '대상 캐릭터 ID(chat_characters.id)',
            'conversation_summary' => '이전 대화 요약(컨텍스트 압축용)',
            'summarized_until_message_id' => '요약이 적용된 마지막 메시지 ID',
            'affinity' => '호감도 게이지(0~100)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'chat_messages' => [
            'id' => '기본 키',
            'user_id' => '메시지 소유 회원 ID(세션 기준)',
            'chat_session_id' => '대화 세션 ID(chat_sessions.id)',
            'role' => '발화 주체(user/character)',
            'content' => '메시지 내용(대사)',
            'narration' => '상황/행동 지문(대사는 content)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── MiniGame ──────────────────────────────────────────
        'game_scores' => [
            'id' => '기본 키',
            'game_key' => '게임 식별 키(GameCatalog key)',
            'user_id' => '등록 회원 ID(비로그인 null)',
            'nickname' => '표시 닉네임(로그인=회원명, 비로그인=입력값)',
            'score' => '점수(높을수록 상위)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 리딤코드 ─────────────────────
        'redeem_codes' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'code' => '코드 문자열(대소문자 보존)',
            'region' => '대상 리전(global/asia/kr/jp/cn)',
            'rewards' => '보상 설명(텍스트)',
            'source' => '수집 소스 키(ennead/mollulog/dc 등)',
            'source_type' => '소스 유형(aggregator/community)',
            'source_url' => '출처 URL',
            'seen_sources' => '교차검증에 관측된 소스 목록(JSON)',
            'corroboration_count' => '교차검증 관측 횟수',
            'status' => '유효성 상태(unverified/active/expired)',
            'found_at' => '최초 수집 시각',
            'last_seen_at' => '마지막 관측 시각',
            'expires_at' => '만료 시각(파악된 경우)',
            'verified_at' => '유효성 확인 시각',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'redeem_code_redemptions' => [
            'id' => '기본 키',
            'user_id' => '교환한 회원 ID(users.id)',
            'redeem_code_id' => '리딤코드 ID(redeem_codes.id)',
            'redeemed_at' => '교환 완료로 표시한 시각',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 게임 마스터 ──────────────────
        'subculture_games' => [
            'id' => '기본 키',
            'slug' => '식별 슬러그(genshin/starrail 등)',
            'name' => '표시 이름(한글)',
            'publisher' => '개발/퍼블리셔',
            'icon' => '카드 아이콘(이모지)',
            'color' => '테마 색상 클래스',
            'redeem_url_template' => '원클릭 교환 직링크 템플릿({code} 치환), 인게임 전용이면 null',
            'redeem_note' => '교환 안내(예: 인게임 전용)',
            'region_default' => '기본 리전(한국 기준 코드 매핑용)',
            'sort' => '정렬 순서',
            'active_flg' => '노출 여부',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 캐릭터/도감/위키 ─────────────
        'subculture_characters' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'external_key' => '크롤 소스 상 고유 식별자(slug/id)',
            'name' => '캐릭터명(한글)',
            'rarity' => '희귀도(블아 성급/니케 SSR 등 게임별 표기)',
            'traits' => '게임별 속성·빌드(공격타입/버스트/티어/에코세트 등 자유 스키마, JSON)',
            'image_url' => '초상 이미지 URL(외부)',
            'image_path' => 'public 디스크 캐시 경로(없으면 image_url 폴백)',
            'source' => '크롤 소스 키(mollulog/letsdoro/triplelab/souseha/manual)',
            'source_url' => '출처 URL',
            'active_flg' => '노출 여부(소스에서 사라지면 소프트 비활성)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_wiki_entries' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'source' => '소스(hoyowiki/wutheringgg)',
            'menu_key' => '카테고리 키(메뉴 id 또는 슬러그)',
            'menu_label' => '카테고리 한글 라벨(에이전트/광추/한정 이벤트 등)',
            'external_key' => '소스 상 항목 id/슬러그',
            'name' => '항목 이름',
            'icon_url' => '아이콘 이미지 URL',
            'filters' => '목록 필터 배지 [{label, value}](JSON)',
            'detail' => '상세 섹션 [{title, rows|paragraphs}](정규화, JSON)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 픽업/이벤트/미래시 ───────────
        'subculture_banners' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'external_key' => '소스 상 고유 식별자(scope+종류+시작시각 등)',
            'scope' => '범위(current=현재/forecast=미래시)',
            'kind' => '종류(character/weapon/light_cone 등)',
            'title' => '배너 제목',
            'featured' => '픽업 대상 [{external_key, name, image, rarity}](JSON)',
            'starts_at' => '시작 시각',
            'ends_at' => '종료 시각',
            'source' => '수집 소스(schaledb/mollulog 등)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_events' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'external_key' => '소스 상 고유 식별자',
            'scope' => '범위(current=현재/forecast=미래시)',
            'kind' => '종류(event/raid/story/maintenance 등)',
            'title' => '이벤트 제목',
            'starts_at' => '시작 시각',
            'ends_at' => '종료 시각',
            'image_url' => '배너 이미지 URL',
            'link_url' => '상세 링크 URL',
            'source' => '수집 소스(schaledb/mollulog 등)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 레이드 ───────────────────────
        'subculture_raids' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'external_key' => '소스 회차 식별자(없으면 sync 가 해시 생성)',
            'name' => '회차/이벤트명(예: 총력전 - 야외 비나)',
            'boss_name' => '보스명(공략글 키워드 매칭 키)',
            'raid_type' => '레이드 종류(총력전/대결전, 솔로/유니온, 프론티어, 길드레이드 등)',
            'tags' => '속성/지형/권장 정보(블아: terrain·armor_type 등, JSON)',
            'starts_at' => '시작 시각',
            'ends_at' => '종료 시각',
            'source' => '크롤 소스 키 또는 manual',
            'source_url' => '출처 URL',
            'note' => '메모',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_raid_parties' => [
            'id' => '기본 키',
            'subculture_raid_id' => '레이드 ID(subculture_raids.id)',
            'title' => '편성 이름(예: 1파티 딜링, 무과금 편성)',
            'difficulty' => '난이도(TORMENT/INSANE, 헬 단계 등)',
            'sort' => '정렬 순서',
            'source' => '크롤 소스 키 또는 manual',
            'source_url' => '출처 URL',
            'note' => '편성 메모',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_raid_party_members' => [
            'id' => '기본 키',
            'subculture_raid_party_id' => '편성 ID(subculture_raid_parties.id)',
            'subculture_character_id' => '캐릭터 ID(subculture_characters.id)',
            'slot_type' => '슬롯 구분(striker/special, 버스트 순번 등)',
            'sort' => '정렬 순서',
            'note' => '대체 가능/필수 여부 등 메모',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_raid_substitutes' => [
            'id' => '기본 키',
            'raid_id' => '레이드 ID(subculture_raids.id)',
            'character_id' => '상위(원) 캐릭터 ID(subculture_characters.id)',
            'substitute_character_id' => '대체 캐릭터 ID(subculture_characters.id)',
            'note' => '대체 조건 메모(예: 풀돌 기준, 스킬 10 필요)',
            'source' => '출처(dc/arca/theqoo/ruliweb/manual)',
            'source_url' => '출처 URL',
            'sort' => '우선순위(낮을수록 우선)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_event_challenges' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'event_key' => '소스 글 식별자(아카 글 ID)',
            'event_name' => '이벤트명',
            'starts_at' => '시작일',
            'ends_at' => '종료일',
            'stage_label' => '스테이지 라벨(Challenge 01 / Challenge EX)',
            'stage_name' => '맵 이름',
            'clear_condition' => '클리어 조건(예: 90초 이내)',
            'summary' => '공략 요약(본문 발췌)',
            'video_url' => '유튜브 공략 영상',
            'extra_videos' => '보조 공략 영상 목록(JSON)',
            'best_party' => '추천 조합 [{name, key}](JSON)',
            'mentioned' => '본문에 언급된 캐릭터 이름들(JSON)',
            'source_url' => '출처 URL',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 공략글 ───────────────────────
        'subculture_guide_posts' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'subculture_raid_id' => '연결 레이드 ID(제목 매칭 시, subculture_raids.id)',
            'source' => '커뮤니티(dc/arca)',
            'external_id' => '글번호(갤러리/채널 내 고유)',
            'title' => '글 제목',
            'url' => '원문 URL',
            'posted_at' => '작성 일시',
            'views' => '조회수',
            'rate' => '추천 수',
            'matched_keyword' => '레이드 매칭에 쓰인 키워드(보스명 등)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 속성 조합(트릭컬) ────────────
        'subculture_attribute_parties' => [
            'id' => '기본 키',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'attribute' => '성격 코드(Jolly/Mad/Cool/Naive/Gloomy) — traits.personality 와 동일 표기',
            'kind' => '종류(curated=큐레이션 추천 / usage=시즌 실측 파생)',
            'source' => '출처(team-manager/trickcalrecord)',
            'title' => '표시 제목(예: 추천 편성, 실측 인기 · 프론티어 시즌18)',
            'source_url' => '출처 URL',
            'period' => '실측 시즌 기간(표시용)',
            'sort' => '정렬 순서',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_attribute_party_members' => [
            'id' => '기본 키',
            'attribute_party_id' => '속성 조합 ID(subculture_attribute_parties.id)',
            'subculture_character_id' => '캐릭터 ID(subculture_characters.id)',
            'position' => '배치(front/middle/back)',
            'sort' => '정렬 순서',
            'meta' => '부가정보(aside=사이드 페어링, usage_pct=시즌 사용률 등, JSON)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — 내 캐릭터 풀/대체 ────────────
        'subculture_user_characters' => [
            'id' => '기본 키',
            'user_id' => '소유 회원 ID(users.id)',
            'subculture_character_id' => '캐릭터 ID(subculture_characters.id)',
            'owned_flg' => '보유 여부',
            'growth' => '성장도(게임별 growth_fields 정의를 따르는 자유 스키마, JSON)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_user_substitutes' => [
            'id' => '기본 키',
            'user_id' => '소유 회원 ID(users.id)',
            'subculture_game_id' => '대상 게임 ID(subculture_games.id)',
            'character_key' => '미보유(원) 캐릭터 external_key',
            'substitute_key' => '대신 쓸 보유 캐릭터 external_key',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],

        // ── SubcultureGameInfo — AI 에이전트 ──────────────────
        'subculture_agent_sessions' => [
            'id' => '기본 키',
            'uuid' => '세션 공개 식별자(UUID)',
            'user_id' => '사용자 회원 ID(비로그인 null)',
            'persona_kind' => '페르소나 종류(preset/character)',
            'persona_ref' => '프리셋 키 또는 chat_characters.id',
            'title' => '세션 제목(첫 질문 요약)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
        'subculture_agent_messages' => [
            'id' => '기본 키',
            'session_id' => '에이전트 세션 ID(subculture_agent_sessions.id)',
            'role' => '발화 주체(user/assistant)',
            'content' => '메시지 텍스트',
            'tool_calls' => '호출한 툴 목록 [{name, args}](진행 표시·감사용, JSON)',
            'cards' => '구조화 카드 [{type, data}](JSON)',
            'created_at' => '생성 일시',
            'updated_at' => '수정 일시',
        ],
    ];

    public function up(): void
    {
        // 컬럼 코멘트는 MySQL 전용 기능 — SQLite(테스트) 등에서는 개념이 없어 스킵.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->comments as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column => $comment) {
                if (Schema::hasColumn($table, $column)) {
                    $this->setComment($table, $column, $comment);
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->comments as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $this->setComment($table, $column, '');
                }
            }
        }
    }

    /**
     * 컬럼의 실제 정의를 information_schema 에서 읽어 그대로 보존한 채 COMMENT 만 교체한다.
     * (타입·charset/collation·null·기본값·auto_increment 를 손대지 않아 스키마 드리프트를 막는다.)
     */
    private function setComment(string $table, string $column, string $comment): void
    {
        $info = DB::selectOne(
            'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), $table, $column]
        );

        if ($info === null) {
            return; // 방어: 컬럼이 없으면 스킵
        }

        $pdo = DB::getPdo();
        $def = $info->COLUMN_TYPE;

        // 문자형이면 실제 charset/collation 을 명시해 테이블 기본값으로 되돌아가는 것을 방지
        if ($info->CHARACTER_SET_NAME !== null) {
            $def .= ' CHARACTER SET '.$info->CHARACTER_SET_NAME.' COLLATE '.$info->COLLATION_NAME;
        }

        $def .= $info->IS_NULLABLE === 'YES' ? ' NULL' : ' NOT NULL';

        if ($info->COLUMN_DEFAULT !== null) {
            $numeric = in_array(strtolower($info->DATA_TYPE), [
                'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
                'decimal', 'numeric', 'float', 'double', 'real', 'bit', 'year',
            ], true);
            // 표현식 기본값(CURRENT_TIMESTAMP 등)은 따옴표 없이. 이 스키마엔 없지만 방어적으로 처리.
            $isExpression = preg_match('/^current_timestamp/i', (string) $info->COLUMN_DEFAULT) === 1;
            $def .= ' DEFAULT '.(($numeric || $isExpression) ? $info->COLUMN_DEFAULT : $pdo->quote($info->COLUMN_DEFAULT));
        }

        if (stripos((string) $info->EXTRA, 'auto_increment') !== false) {
            $def .= ' AUTO_INCREMENT';
        }

        $def .= ' COMMENT '.$pdo->quote($comment);

        DB::statement(sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $table, $column, $def));
    }
};
