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
            // IP 표준명은 시그니처에서 제외 — IP 는 별도 축(ok_product_ip_id)이 담당하고,
            // 제목에 IP명이 있냐 없냐(대괄호 말머리 여부 등)로 시그니처가 갈라지는 것을 막는다.
            $this->stopwords[mb_strtolower($canonical)] = true;
        }

        // 일반 토큰 별칭(라인명 등) — 매칭 치환에만 쓰고 IP 분류에는 넣지 않는다.
        foreach ((array) config('otaku-crawler.product_match.token_aliases', []) as $canonical => $variants) {
            $this->aliases[mb_strtolower($canonical)] = array_map('mb_strtolower', (array) $variants);
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
     * 제목에서 발매(예정)일을 뽑아 Y-m-d(일=01)로 반환. 없으면 null.
     * 발매/입고/출시/예정 키워드가 함께 있을 때만 인정해 과추출을 막는다.
     */
    public function extractReleaseDate(string $title): ?string
    {
        return $this->parseReleaseFromText($title, requireKeyword: true);
    }

    /**
     * 텍스트에서 "NN년 NN월"(범위면 첫 달)을 발매일로 파싱한다.
     * - requireKeyword=true: 발매/입고/출시/예정 키워드가 있어야 인정(제목용, 과추출 방지).
     * - requireKeyword=false: 발매일 전용 필드값(예: cafe24 "발매 : 26년 11월")을 그대로 파싱.
     */
    public function parseReleaseFromText(string $text, bool $requireKeyword = true): ?string
    {
        if ($requireKeyword && ! preg_match('/(발매|입고|출시|예정)/u', $text)) {
            return null;
        }

        // 'NN년 NN월'(한국어 표기, 범위면 첫 달)
        if (preg_match('/(\d{2,4})\s*년\s*(\d{1,2})\s*월/u', $text, $m)) {
            return $this->buildReleaseDate((int) $m[1], (int) $m[2]);
        }

        // 'YYYY/MM' · 'YYYY-MM' · 'YYYY.MM'(숫자 표기, 예: 굿스마일 "발매시기 2026/11")
        if (preg_match('#(20\d{2})\s*[/.\-]\s*(\d{1,2})#', $text, $m)) {
            return $this->buildReleaseDate((int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /** 연/월(2자리 연도는 2000년대로 보정)로 발매일 Y-m-01 문자열을 만든다. 월 범위 밖이면 null. */
    private function buildReleaseDate(int $year, int $month): ?string
    {
        if ($year < 100) {
            $year += 2000;  // 2자리 연도(26년 → 2026)
        }
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
        $kept = $this->distinctiveTokens($normalized);

        if (count($kept) < 2) {
            return str_replace(' ', '', $normalized);
        }

        sort($kept, SORT_STRING);  // 단어 순서 차이 흡수

        return implode(' ', $kept);
    }

    /**
     * 이름 유사(포함관계) 매칭용 변별 토큰 집합. 정렬·중복제거된 토큰 배열을 반환한다.
     * 토큰이 2개 미만이면 과매칭 방지를 위해 빈 배열을 반환한다(퍼지 매칭 제외).
     *
     * @return array<int, string>
     */
    public function signatureTokens(string $title): array
    {
        $tokens = $this->distinctiveTokens($this->normalizeTitle($title));
        if (count($tokens) < 2) {
            return [];
        }

        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * 변별 토큰(정렬·중복제거)을 토큰 1개여도 그대로 반환한다.
     * signatureTokens 는 과매칭 방지로 2개 미만이면 []를 주지만, 이미지 확증 병합의 '캐릭터 충돌'
     * 판정에는 단일 캐릭터명(프라나 등)도 살려야 서로 다른 캐릭터(프라나 vs 호시노)를 구분할 수 있다.
     *
     * @return array<int, string>
     */
    public function primaryTokens(string $title): array
    {
        $tokens = $this->distinctiveTokens($this->normalizeTitle($title));
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * 정규화된 제목에서 변별 토큰(불용어·단독 숫자·1글자 제외, 중복 제거)을 뽑는다.
     *
     * @return array<int, string>
     */
    private function distinctiveTokens(string $normalized): array
    {
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

        return array_keys($kept);
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
