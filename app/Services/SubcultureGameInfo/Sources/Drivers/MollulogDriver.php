<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * mollulog.net — 블루 아카이브 전용 쿠폰 정리 사이트(넥슨 공식 포럼을 취합).
 *
 * 블아 KR은 다른 게임과 달리 네이버 라운지/아카에 '쿠폰 게시판'이 없어 구조화 수집이 어렵다.
 * mollulog 는 넥슨 공식 커뮤니티(forum.nexon.com/bluearchive) 글을 정리하며, 각 쿠폰에
 * "code / 사용기한(expiresAt) / 공식 링크" 를 담는다. → 만료일까지 정확히 가져올 수 있는 소스.
 *
 * mollulog 는 Remix SPA 라 HTML 을 긁으면 코드만 나오고 날짜가 없다. 대신 라우트 데이터
 * 엔드포인트(/coupons.data, turbo-stream)를 받아 인덱스 참조를 풀어 구조화 데이터를 얻는다.
 */
class MollulogDriver extends AbstractSourceDriver
{
    private const DATA_URL = 'https://mollulog.net/coupons.data';

    public function driverKey(): string
    {
        return 'mollulog';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        // mollulog 는 블루 아카이브 전용.
        if ($gameSlug !== 'bluearchive') {
            return [];
        }

        $coupons = $this->fetchCoupons();
        if ($coupons === []) {
            return [];
        }

        $region = $this->regionFor($gameSlug);
        $byCode = [];

        foreach ($coupons as $c) {
            $code = is_string($c['code'] ?? null) ? trim($c['code']) : null;
            if ($code === null || ! preg_match('/^[A-Za-z0-9]{4,30}$/', $code)) {
                continue;
            }

            $expiresAt = $this->parseIso($c['expiresAt'] ?? null);
            // 공식 포럼 기준 기한이 있으면 그대로 신뢰: 지났으면 만료, 남았으면 사용가능.
            $status = ($expiresAt !== null && $expiresAt->isPast()) ? CodeStatus::Expired : CodeStatus::Active;

            $byCode[strtoupper($code)] ??= new CollectedCodeDto(
                gameSlug: $gameSlug,
                code: $code,
                sourceType: SourceType::Aggregator,
                source: 'mollulog',
                region: $region,
                rewards: is_string($c['name'] ?? null) && trim($c['name']) !== '' ? trim($c['name']) : null,
                status: $status,
                sourceUrl: is_string($c['linkUrl'] ?? null) && $c['linkUrl'] !== '' ? $c['linkUrl'] : 'https://mollulog.net/coupons',
                expiresAt: $expiresAt,
            );
        }

        return array_values($byCode);
    }

    /**
     * /coupons.data(turbo-stream)를 받아 쿠폰 객체 배열로 복원한다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCoupons(): array
    {
        try {
            $res = $this->http()->withHeaders(['Accept' => 'text/x-turbo, */*'])->get(self::DATA_URL);
            if (! $res->successful()) {
                Log::warning('[SGI] mollulog 데이터 요청 실패', ['status' => $res->status()]);

                return [];
            }

            return $this->parseTurboStream($res->body());
        } catch (\Throwable $e) {
            Log::warning('[SGI] mollulog 파싱 예외', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * turbo-stream(플랫 배열 + 인덱스 참조)에서 'coupons' 목록을 찾아 각 객체를 {키:값}으로 복원.
     * 형식: 각 원소가 값이고, 객체는 {"_<키인덱스>": <값인덱스>} 로 다른 원소를 가리킨다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseTurboStream(string $raw): array
    {
        $arr = json_decode($raw, true);
        if (! is_array($arr)) {
            return [];
        }

        $cIdx = array_search('coupons', $arr, true);
        if ($cIdx === false || ! isset($arr[$cIdx + 1]) || ! is_array($arr[$cIdx + 1])) {
            return [];
        }

        $rows = [];
        foreach ($arr[$cIdx + 1] as $refIndex) {
            if (! is_int($refIndex) || ! isset($arr[$refIndex]) || ! is_array($arr[$refIndex])) {
                continue;
            }
            $row = [];
            foreach ($arr[$refIndex] as $key => $valIndex) {
                if (! is_string($key) || ($key[0] ?? '') !== '_') {
                    continue;
                }
                $keyName = $arr[(int) substr($key, 1)] ?? null;
                if (! is_string($keyName)) {
                    continue;
                }
                $row[$keyName] = is_int($valIndex) ? ($arr[$valIndex] ?? null) : $valIndex;
            }
            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function parseIso(mixed $s): ?Carbon
    {
        if (! is_string($s) || $s === '') {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }
}
