<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 캐릭터 외부 이미지를 public 디스크에 1회 다운로드 캐시한다.
 * 원본(개인 팬사이트 CDN)이 폐쇄돼도 화면이 깨지지 않고, 원 사이트에 트래픽 부담을 주지 않기 위함.
 * 실패는 로그 후 무시 — image_path 가 비면 프론트가 image_url(원본)로 폴백한다.
 */
class CharacterImageCacheService
{
    private const DISK = 'public';

    private const BASE_DIR = 'subculture/characters';

    /** content-type → 확장자 (이외 타입은 이미지가 아니므로 캐시하지 않는다) */
    private const EXTENSIONS = [
        'image/webp' => 'webp',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    /**
     * 필요 시 이미지를 내려받아 image_path 를 채운다(멱등).
     *
     * @param  bool  $force  원본 URL 이 바뀐 경우 등 기존 캐시를 무시하고 재다운로드
     */
    public function cache(Character $character, bool $force = false): void
    {
        $url = (string) $character->image_url;
        if (! str_starts_with($url, 'http')) {
            return;
        }
        if (! $force && $character->image_path && Storage::disk(self::DISK)->exists($character->image_path)) {
            return;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => (string) config('subculture-game-info.http.user_agent')])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('[SGI-RAID] 캐릭터 이미지 다운로드 예외', ['url' => $url, 'error' => $e->getMessage()]);

            return;
        }

        $extension = self::EXTENSIONS[strtolower(strtok((string) $response->header('Content-Type'), ';'))] ?? null;
        if (! $response->successful() || $extension === null || $response->body() === '') {
            Log::warning('[SGI-RAID] 캐릭터 이미지 응답 비정상 — 캐시 스킵', [
                'url' => $url, 'status' => $response->status(), 'content_type' => $response->header('Content-Type'),
            ]);

            return;
        }

        $slug = $character->game?->slug ?? 'unknown';
        $safeKey = preg_replace('/[^A-Za-z0-9._-]/', '-', $character->external_key);
        $path = self::BASE_DIR."/{$slug}/{$safeKey}.{$extension}";

        Storage::disk(self::DISK)->put($path, $response->body());
        $character->forceFill(['image_path' => $path])->save();
    }
}
