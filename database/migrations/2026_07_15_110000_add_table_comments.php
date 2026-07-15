<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 테이블 자체(table-level)에 한국어 설명(COMMENT)을 단다.
 * 컬럼 코멘트는 각 생성 마이그레이션에 이미 있고, 여기서는 테이블 단위 설명만 채운다.
 * Laravel Blueprint 에 테이블 코멘트 메서드가 없어 raw ALTER(MySQL 8 에서 즉시 메타 변경)로 처리.
 * 프레임워크 기본 테이블(migrations/sessions/password_reset_tokens 등)은 대상에서 제외.
 */
return new class extends Migration
{
    /** @var array<string, string> 테이블 => 설명 */
    private array $comments = [
        // 공통
        'users' => '회원 계정(이메일·비밀번호·닉네임)',
        'social_accounts' => '소셜 로그인 연동 계정(카카오·구글 등)',
        'push_subscriptions' => '웹푸시(PWA) 구독 정보',
        'inquiries' => '문의하기 접수 내역',
        'access_logs' => '외부 유저 접속 로그(방문 페이지·유입경로·기기·UA·IP)',

        // OtakuShop — 오타쿠 굿즈 가격비교
        'otaku_shop' => 'OtakuShop - 판매처/쇼핑몰 정보',
        'otaku_category' => 'OtakuShop - 공통 상품 카테고리',
        'otaku_ip' => 'OtakuShop - IP(작품) 분류',
        'otaku_product' => 'OtakuShop - 정규화된 비교 대상 상품',
        'otaku_offer' => 'OtakuShop - 상품별 쇼핑몰 오퍼(가격/재고/링크·최저가 플래그)',
        'otaku_wish' => 'OtakuShop - 회원 찜(위시리스트)',

        // MyWifeBot — AI 캐릭터 채팅
        'chat_characters' => 'MyWifeBot - 챗봇 캐릭터(페르소나)',
        'chat_sessions' => 'MyWifeBot - 챗봇 대화 세션(캐릭터별 대화 단위)',
        'chat_messages' => 'MyWifeBot - 대화 메시지(role: user/character)',

        // MiniGame
        'game_scores' => 'MiniGame - 게임별 점수 랭킹',

        // SubcultureGameInfo — 리딤코드
        'redeem_codes' => '서브컬쳐 게임 리딤/쿠폰 코드',
        'redeem_code_redemptions' => '리딤코드 교환완료 기록(로그인 사용자)',

        // SubcultureGameInfo — 정보검색(레이드·캐릭터·일정·공략)
        'subculture_games' => '서브컬쳐 게임 마스터(config games 동기화)',
        'subculture_characters' => '게임별 캐릭터 마스터(도감·빌드 traits JSON)',
        'subculture_wiki_entries' => '게임 위키 항목(호요랩 위키·wuthering.gg 등)',
        'subculture_banners' => '픽업 배너(모집중 학생·미래시)',
        'subculture_events' => '진행중/예정 게임 이벤트',
        'subculture_raids' => '레이드 회차(총력전·대결전·종합전술시험 등)',
        'subculture_raid_parties' => '레이드 추천 편성',
        'subculture_raid_party_members' => '레이드 추천 편성 멤버',
        'subculture_raid_substitutes' => '레이드 대체 캐릭터(커뮤니티/수동 추출)',
        'subculture_event_challenges' => '이벤트 챌린지 스테이지별 공략(블아)',
        'subculture_guide_posts' => '커뮤니티 공략글 메타(디씨·아카)',
        'subculture_attribute_parties' => '속성(성격)별 추천 조합(트릭컬)',
        'subculture_attribute_party_members' => '속성별 추천 조합 멤버',
        'subculture_user_characters' => '내 캐릭터 풀(보유 + 성장도 growth JSON)',
        'subculture_user_substitutes' => '내 대체 캐릭터 지정(미보유→보유 매핑)',

        // SubcultureGameInfo — AI 에이전트
        'subculture_agent_sessions' => 'AI 에이전트 대화 세션',
        'subculture_agent_messages' => 'AI 에이전트 대화 메시지(툴 호출·카드 포함)',
    ];

    public function up(): void
    {
        foreach ($this->comments as $table => $comment) {
            if (Schema::hasTable($table)) {
                DB::statement(sprintf('ALTER TABLE `%s` COMMENT = %s', $table, DB::getPdo()->quote($comment)));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->comments) as $table) {
            if (Schema::hasTable($table)) {
                DB::statement(sprintf("ALTER TABLE `%s` COMMENT = ''", $table));
            }
        }
    }
};
