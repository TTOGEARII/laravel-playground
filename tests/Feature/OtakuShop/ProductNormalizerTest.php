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
}
