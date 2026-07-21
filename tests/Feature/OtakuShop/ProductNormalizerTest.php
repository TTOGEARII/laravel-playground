<?php

namespace Tests\Feature\OtakuShop;

use App\Services\OtakuShop\Crawler\ProductNormalizer;
use Tests\TestCase;

class ProductNormalizerTest extends TestCase
{
    private function normalizer(): ProductNormalizer
    {
        // config(otaku-crawler.product_match)를 사용하도록 컨테이너에서 생성.
        return $this->app->make(ProductNormalizer::class);
    }

    public function test_verbose_and_terse_titles_of_same_product_match(): void
    {
        $n = $this->normalizer();

        // 실제 데이터: 한 쇼핑몰은 제조사·라인명·넨도번호까지 장황하게, 다른 곳은 간결하게 적는다.
        // 노이즈 토큰을 걷어낸 변별 토큰(IP명·캐릭터명)으로 같은 상품으로 묶여야 한다.
        $this->assertSame(
            $n->normalizeKey('[26년 12월 발매] 에반게리온 신극장판 굿스마일 컴퍼니 넨도로이드 2930 피규어 - 시키나미 아스카 랑그레이'),
            $n->normalizeKey('[예약]에반게리온 넨도로이드 시키나미 아스카 랑그레이(재판)'),
        );
    }

    public function test_word_order_and_case_do_not_affect_match(): void
    {
        $n = $this->normalizer();

        $this->assertSame(
            $n->normalizeKey('원신 넨도로이드 벤티'),
            $n->normalizeKey('벤티  넨도로이드  원신'),
        );
        $this->assertSame(
            $n->normalizeKey('블루아카이브 FigUnity 아로나'),
            $n->normalizeKey('블루아카이브 figunity 아로나'),
        );
    }

    public function test_edition_and_release_noise_is_ignored_for_matching(): void
    {
        $n = $this->normalizer();
        $base = $n->normalizeKey('블루 아카이브 메가하우스 FigUnity 피규어 - 흥신소 68');

        // 일반판 / 특전판 / 발매일 말머리는 같은 상품으로 묶여야 한다.
        $this->assertSame($base, $n->normalizeKey('[특전] 블루 아카이브 메가하우스 FigUnity 피규어 - 흥신소 68'));
        $this->assertSame($base, $n->normalizeKey('[27년 02월 발매] 블루 아카이브 메가하우스 FigUnity 피규어 - 흥신소 68'));
        $this->assertSame($base, $n->normalizeKey('【굿즈】블루 아카이브 메가하우스 FigUnity 피규어 - 흥신소 68'));
    }

    public function test_ip_name_spacing_and_abbreviation_aliases_match(): void
    {
        $n = $this->normalizer();

        // 작품명 표기(띄어쓰기/줄임말)가 달라도 같은 상품으로 묶여야 한다.
        $base = $n->normalizeKey('블루 아카이브 아로나 아크릴 스탠드');
        $this->assertSame($base, $n->normalizeKey('블루아카이브 아로나 아크릴 스탠드'));
        $this->assertSame($base, $n->normalizeKey('블아 아로나 아크릴 스탠드'));

        // 실제 데이터: "페스나"(페이트 스테이 나이트 줄임말)만 덧붙은 동일 상품.
        $this->assertSame(
            $n->normalizeKey('페이트 스테이 나이트 페스나 굿스마일 컴퍼니 넨도로이드 3078 피규어 - 세이버 사복 Ver.'),
            $n->normalizeKey('페이트 스테이 나이트 넨도로이드 세이버 사복 Ver.'),
        );
    }

    public function test_same_ip_different_character_still_separate(): void
    {
        $n = $this->normalizer();

        // 별칭 통일 후에도 캐릭터가 다르면 다른 상품으로 남아야 한다(과매칭 방지).
        $this->assertNotSame(
            $n->normalizeKey('블아 넨도로이드 아로나'),
            $n->normalizeKey('블루 아카이브 넨도로이드 미카'),
        );
    }

    public function test_different_products_do_not_match(): void
    {
        $n = $this->normalizer();

        $this->assertNotSame(
            $n->normalizeKey('블루 아카이브 FigUnity 피규어 - 흥신소 68'),
            $n->normalizeKey('블루 아카이브 FigUnity 피규어 - 아로나'),
        );
    }

    public function test_brand_label_is_part_of_key(): void
    {
        $n = $this->normalizer();

        $this->assertNotSame(
            $n->normalizeKey('아크릴 스탠드 아로나', '굿스마일'),
            $n->normalizeKey('아크릴 스탠드 아로나', '메가하우스'),
        );
    }

    public function test_extract_release_date_from_title(): void
    {
        $n = $this->normalizer();

        // [NN년 NN월 발매] → 그 달 1일.
        $this->assertSame('2026-02-01', $n->extractReleaseDate('[26년 02월 발매] 귀멸의 칼날 굿즈'));
        $this->assertSame('2025-12-01', $n->extractReleaseDate('[25년 12월 발매][재판] 블루 아카이브 키링'));
        // 한 자리 월도 처리.
        $this->assertSame('2026-09-01', $n->extractReleaseDate('[26년 9월 발매] 원신 아크릴'));
        // 발매 표기가 없으면 null.
        $this->assertNull($n->extractReleaseDate('[입고 완료] 명일방주 인형'));
        $this->assertNull($n->extractReleaseDate('블루 아카이브 아로나 피규어'));
        // 입고예정·범위 표기도 첫 달로(피규어프레소 제목 포맷).
        $this->assertSame('2027-01-01', $n->extractReleaseDate('[예약상품/27년 01월~02월 입고예정][반다이남코] 다마고치'));
        // 키워드 없는 단순 연·월은 과추출 방지로 null.
        $this->assertNull($n->extractReleaseDate('2024년 한정 굿즈'));
    }

    public function test_parse_release_from_text_without_keyword(): void
    {
        $n = $this->normalizer();

        // 발매일 전용 필드값(예: cafe24 "발매 : 26년 11월")은 키워드 없이도 파싱.
        $this->assertSame('2026-11-01', $n->parseReleaseFromText('26년 11월', false));
        $this->assertSame('2027-03-01', $n->parseReleaseFromText('2027년 3월', false));
        $this->assertNull($n->parseReleaseFromText('미정', false));
        // 숫자 표기(굿스마일 "발매시기 2026/11" → "2026/11")도 파싱.
        $this->assertSame('2026-11-01', $n->parseReleaseFromText('2026/11', false));
        $this->assertSame('2026-11-01', $n->parseReleaseFromText('발매시기 2026-11', false));
    }

    public function test_extract_ip_code_from_title(): void
    {
        $n = $this->normalizer();

        // 표준명/띄어쓰기/줄임말 모두 같은 IP 코드로.
        $this->assertSame('블루아카이브', $n->extractIpCode('[26년 02월 발매] 블루 아카이브 아로나 아크릴'));
        $this->assertSame('블루아카이브', $n->extractIpCode('블아 공식 굿즈 키링'));
        $this->assertSame('귀멸의칼날', $n->extractIpCode('[26년 02월 발매] 귀멸의 칼날 귀칼 굿즈 파샤코레'));
        // 새로 보강한 IP.
        $this->assertSame('나의히어로아카데미아', $n->extractIpCode('나의 히어로 아카데미아 데쿠 피규어'));
        $this->assertSame('승리의여신니케', $n->extractIpCode('승리의 여신: 니케 폴라로이드'));
        // 사전에 없는 작품은 null.
        $this->assertNull($n->extractIpCode('이름없는 무명 작품 아크릴 스탠드'));
    }

    public function test_extract_maker_code_handles_number_prefixes(): void
    {
        $n = $this->normalizer();

        // 넨도로이드 번호 앞 표기(공백/#/No./№/넘버)가 달라도 같은 코드로 뽑힌다.
        $this->assertSame('nendo_2611', $n->extractMakerCode('블루 아카이브 넨도로이드 2611 이치노세 아스나'));
        $this->assertSame('nendo_2611', $n->extractMakerCode('블루 아카이브 넨도로이드 No.2611 이치노세 아스나'));
        $this->assertSame('nendo_2611', $n->extractMakerCode('블루 아카이브 넨도로이드 #2611 아스나'));
        $this->assertSame('nendo_2611', $n->extractMakerCode('블루 아카이브 넨도로이드 넘버2611 아스나'));

        // 피규어츠는 전체 표기를 요구한다(바 "츠" 오탐 방지).
        $this->assertSame('figuarts_123', $n->extractMakerCode('S.H.피규어츠 No.123 손오공'));
        $this->assertSame('figuarts_77', $n->extractMakerCode('figuarts 77 가면라이더'));
        // "리츠 10th", "미네츠키" 같은 일반어의 "츠 + 숫자"는 매칭되면 안 된다.
        $this->assertNull($n->extractMakerCode('BanG Dream! 아크릴스탠드 미네츠키 리츠 10th Anniversary'));
        $this->assertNull($n->extractMakerCode('파츠 5종 세트'));
    }

    public function test_signature_tokens_returns_sorted_distinctive_tokens(): void
    {
        $n = $this->normalizer();

        $title = '[예약] 블루 아카이브 아스나 교복 메모리얼 로비 피규어';
        $tokens = $n->signatureTokens($title);
        // 노이즈(예약/피규어/단독숫자)는 빠지고 변별 토큰만, 정렬되어 반환.
        $this->assertContains('아스나', $tokens);
        $this->assertNotContains('피규어', $tokens);
        // IP명(블루아카이브)은 시그니처 토큰에서 제외 — 매칭은 별도 축(extractIpCode/ok_product_ip_id)이 담당해
        // 제목에 IP명이 있냐 없냐로 시그니처가 갈라지지 않게 한다.
        $this->assertNotContains('블루아카이브', $tokens);
        $this->assertSame('블루아카이브', $n->extractIpCode($title));
        $sorted = $tokens;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $tokens);
    }
}
