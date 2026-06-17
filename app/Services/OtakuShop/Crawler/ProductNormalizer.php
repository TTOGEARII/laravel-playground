<?php

namespace App\Services\OtakuShop\Crawler;

/**
 * 동일 상품 그룹핑(가격비교)용 제목 정규화.
 *
 * 쇼핑몰마다 같은 상품의 제목 표기가 크게 다르다(제조사/라인명/넨도 번호/발매정보 등을
 * 붙이거나 뺀다). 그래서 단순 제목 해시로는 매칭이 거의 안 된다.
 * 이 클래스는 노이즈 토큰을 걷어낸 "변별 토큰들의 정렬 시그니처"로 키를 만들어
 * 서로 다른 쇼핑몰의 동일 상품을 묶는다.
 *
 *   예) "에반게리온 신극장판 굿스마일 컴퍼니 넨도로이드 2930 피규어 - 시키나미 아스카 랑그레이"
 *       "에반게리온 넨도로이드 시키나미 아스카 랑그레이"
 *       → 둘 다 시그니처: "넨도로이드 랑그레이 시키나미 아스카 에반게리온"
 */
class ProductNormalizer
{
    private int $titleMinLength;

    private array $stripPatterns;

    /** @var array<string, true> */
    private array $stopwords;

    /** @var array<string, array<int, string>> 표준토큰 => [별칭 표기들] */
    private array $aliases;

    public function __construct(
        ?int $titleMinLength = null,
        ?array $stripPatterns = null,
        ?array $stopwords = null,
        ?array $aliases = null,
    ) {
        // 인자를 명시하지 않으면(컨테이너 자동 주입 포함) config 값을 사용한다.
        $this->stripPatterns = $stripPatterns ?? config('otaku-crawler.product_match.strip_patterns', []);
        $this->titleMinLength = $titleMinLength ?? (int) config('otaku-crawler.product_match.title_min_length', 5);

        $words = $stopwords ?? config('otaku-crawler.product_match.match_stopwords', []);
        $this->stopwords = [];
        foreach ($words as $word) {
            $this->stopwords[mb_strtolower($word)] = true;
        }

        $this->aliases = [];
        foreach ($aliases ?? config('otaku-crawler.product_match.ip_aliases', []) as $canonical => $variants) {
            $this->aliases[mb_strtolower($canonical)] = array_map('mb_strtolower', $variants);
        }
    }

    /**
     * 상품 정규화 키 (동일 상품 매칭용).
     */
    public function normalizeKey(string $title, ?string $brandLabel = null): string
    {
        $brandPart = $brandLabel !== null && trim($brandLabel) !== ''
            ? str_replace(' ', '', mb_strtolower(trim($brandLabel)))
            : '';

        return 'pr_'.md5($this->signature($title).'|'.$brandPart);
    }

    /**
     * 변별 토큰들의 정렬 시그니처. 토큰이 너무 적게 남으면 과매칭 방지를 위해
     * 공백 제거한 전체 정규화 제목으로 폴백한다.
     */
    public function signature(string $title): string
    {
        $normalized = $this->normalizeTitle($title);

        $kept = [];
        foreach (explode(' ', $normalized) as $token) {
            if ($token === '' || isset($this->stopwords[$token])) {
                continue;
            }
            if (preg_match('/^\d+$/', $token)) {  // 넨도 번호/발매연도 등 단독 숫자
                continue;
            }
            if (mb_strlen($token) < 2) {           // 1글자 토큰(조사/구분자 잔여)
                continue;
            }
            $kept[$token] = true;                  // 중복 토큰 제거
        }

        $kept = array_keys($kept);
        if (count($kept) < 2) {
            return str_replace(' ', '', $normalized);
        }

        sort($kept, SORT_STRING);  // 단어 순서 차이 흡수

        return implode(' ', $kept);
    }

    /**
     * 제목 정규화 (매칭용 — 표시/검색용 아님).
     * 소문자화 → 노이즈 패턴 제거 → 구분기호를 공백으로 → 공백 1칸으로 축약.
     */
    public function normalizeTitle(string $title): string
    {
        $normalized = mb_strtolower(trim($title));

        foreach ($this->stripPatterns as $pattern) {
            $normalized = preg_replace($pattern, ' ', $normalized) ?? $normalized;
        }

        // 구분 기호류(하이픈/슬래시/구두점/괄호)를 공백으로 — 표기 차이를 흡수.
        $normalized = preg_replace('#[\-_/·:;,.~!?*"\'()\[\]<>{}|]+#u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        $normalized = $this->applyAliases($normalized);

        // 과도하게 깎여 너무 짧아지면 원제목(소문자) 기준으로 폴백.
        if (mb_strlen($normalized) < $this->titleMinLength) {
            return mb_strtolower(trim($title));
        }

        return $normalized;
    }

    /**
     * IP명 별칭(띄어쓰기/줄임말)을 표준 토큰으로 합친다. 토큰 경계를 지키기 위해
     * 양끝을 공백으로 감싼 뒤 치환한다.
     */
    private function applyAliases(string $spaced): string
    {
        if ($this->aliases === []) {
            return $spaced;
        }

        $padded = ' '.$spaced.' ';
        foreach ($this->aliases as $canonical => $variants) {
            foreach ($variants as $variant) {
                $padded = str_replace(' '.$variant.' ', ' '.$canonical.' ', $padded);
            }
        }

        return trim($padded);
    }
}
