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

    /** @var array<string, array<int, string>> IP코드(원본 표기) => [매칭 검색어(소문자)] — 분류용 */
    private array $ipClassify;

    /** @var array<string, string> 코드 접두사 => 추출 정규식 — 고유값(품번) 추출용 */
    private array $makerCodePatterns;

    public function __construct(
        ?int $titleMinLength = null,
        ?array $stripPatterns = null,
        ?array $stopwords = null,
        ?array $aliases = null,
        ?array $makerCodePatterns = null,
    ) {
        $this->makerCodePatterns = $makerCodePatterns ?? config('otaku-crawler.product_match.maker_code_patterns', []);
        // 인자를 명시하지 않으면(컨테이너 자동 주입 포함) config 값을 사용한다.
        $this->stripPatterns = $stripPatterns ?? config('otaku-crawler.product_match.strip_patterns', []);
        $this->titleMinLength = $titleMinLength ?? (int) config('otaku-crawler.product_match.title_min_length', 5);

        $words = $stopwords ?? config('otaku-crawler.product_match.match_stopwords', []);
        $this->stopwords = [];
        foreach ($words as $word) {
            $this->stopwords[mb_strtolower($word)] = true;
        }

        $aliasSource = $aliases ?? config('otaku-crawler.product_match.ip_aliases', []);
        $this->aliases = [];
        $this->ipClassify = [];
        foreach ($aliasSource as $canonical => $variants) {
            $this->aliases[mb_strtolower($canonical)] = array_map('mb_strtolower', $variants);
            // 분류는 원본 코드를 키로, 표준명+별칭 전부를 검색어로(소문자) 둔다.
            $this->ipClassify[$canonical] = array_map('mb_strtolower', array_merge([$canonical], $variants));
        }
    }

    /**
     * 제목에서 IP(작품) 코드를 추출한다(별칭 사전 부분일치, 먼저 매칭되는 것). 없으면 null.
     */
    public function extractIpCode(string $title): ?string
    {
        $low = mb_strtolower($title);
        foreach ($this->ipClassify as $code => $terms) {
            foreach ($terms as $term) {
                if ($term !== '' && mb_strpos($low, $term) !== false) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * 제목에서 상품 고유값(제조사 품번/모델 번호)을 추출한다. 패턴은 위에서부터 먼저 매칭되는 것을 쓴다.
     * 반환 형식은 "접두사_번호"(예: nendo_2930, jan_4571368459701). 없으면 null.
     */
    public function extractMakerCode(string $title): ?string
    {
        foreach ($this->makerCodePatterns as $prefix => $pattern) {
            if (preg_match($pattern, $title, $m) && isset($m[1]) && $m[1] !== '') {
                return $prefix.'_'.$m[1];
            }
        }

        return null;
    }

    /**
     * 제목의 "[NN년 NN월 발매]"(또는 4자리 연도)에서 발매(예정)일을 뽑아 Y-m-d(일=01)로 반환. 없으면 null.
     */
    public function extractReleaseDate(string $title): ?string
    {
        if (! preg_match('/(\d{2,4})\s*년\s*(\d{1,2})\s*월\s*발매/u', $title, $m)) {
            return null;
        }

        $year = (int) $m[1];
        if ($year < 100) {
            $year += 2000;  // 2자리 연도(26년 → 2026)
        }
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%04d-%02d-01', $year, $month);
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
