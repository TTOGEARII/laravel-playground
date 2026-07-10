<?php

namespace App\Services\OtakuShop\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 상품 이미지 지각 해시(dHash) — 쇼핑몰들이 같은 공식 이미지를 다른 크기/품질로
 * 재압축해 올려도 "보이는 모습"이 같으면 가까운 지문이 나온다(외부 API 비용 0원).
 *
 * 방식: 9×8 그레이스케일로 축소 → 행마다 인접 픽셀 밝기 비교(좌>우=1) → 64bit → hex 16자.
 * 두 지문의 해밍 거리(다른 비트 수)가 임계값 이하면 동일 이미지로 본다.
 */
class ImageHasher
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    /** 이미지 URL → dHash(hex 16자). 실패 시 null. */
    public function hashFromUrl(string $url): ?string
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(10)
                ->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $this->hashFromBinary($response->body());
        } catch (\Throwable $e) {
            Log::info('[OTAKU-IMG] 이미지 다운로드 실패', ['url' => mb_substr($url, 0, 120), 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** 이미지 바이너리 → dHash(hex 16자). 실패 시 null. */
    public function hashFromBinary(string $binary): ?string
    {
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }

        // 9×8 축소(비율 무시 — 지문 비교 목적) 후 밝기 계산. GdImage 는 GC 가 해제한다.
        $thumb = imagecreatetruecolor(9, 8);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                // 표준 휘도 근사(0.299R + 0.587G + 0.114B)
                $lum = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($prev !== null) {
                    $bits .= $prev > $lum ? '1' : '0';
                }
                $prev = $lum;
            }
        }
        // 64bit 를 한 번에 변환하면 float 정밀도가 깨진다 — 8bit 씩 hex 로
        $hex = '';
        foreach (str_split($bits, 8) as $byte) {
            $hex .= str_pad(dechex((int) bindec($byte)), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /** 두 해시(hex 16자)의 해밍 거리(0~64). 형식이 어긋나면 최대값(64) 반환. */
    public static function distance(string $hexA, string $hexB): int
    {
        if (strlen($hexA) !== 16 || strlen($hexB) !== 16) {
            return 64;
        }

        $distance = 0;
        for ($i = 0; $i < 16; $i += 2) {
            $xor = hexdec(substr($hexA, $i, 2)) ^ hexdec(substr($hexB, $i, 2));
            while ($xor > 0) {
                $distance += $xor & 1;
                $xor >>= 1;
            }
        }

        return $distance;
    }
}
