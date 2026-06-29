<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 호요버스 3종(원신/스타레일/젠레스) — 비공식 JSON 집계 API(ennead.cc) 소비.
 * 응답: { active: [{code, rewards:[...]}], inactive: [{code, rewards:[...]}] }
 * 정적 크롤/헤드리스 불필요. active→사용가능, inactive→만료.
 */
class HoyoverseApiSource extends AbstractCodeSource
{
    public function key(): string
    {
        return 'ennead';
    }

    public function fetch(): array
    {
        $cfg = config('subculture-game-info.sources.hoyoverse_api');
        $base = rtrim($cfg['base'], '/');
        $out = [];

        foreach ($cfg['endpoints'] as $gameSlug => $segment) {
            $url = "{$base}/{$segment}/codes";
            $json = $this->getJson($url);
            if (! is_array($json)) {
                continue;
            }

            foreach (['active' => CodeStatus::Active, 'inactive' => CodeStatus::Expired] as $bucket => $status) {
                foreach ($json[$bucket] ?? [] as $entry) {
                    $code = is_array($entry) ? ($entry['code'] ?? null) : (is_string($entry) ? $entry : null);
                    if (! is_string($code) || $code === '') {
                        continue;
                    }

                    $out[] = new CollectedCodeDto(
                        gameSlug: $gameSlug,
                        code: trim($code),
                        sourceType: SourceType::Aggregator,
                        source: $this->key(),
                        region: CodeRegion::Global,
                        rewards: $this->normalizeRewards($entry['rewards'] ?? null),
                        status: $status,
                        sourceUrl: $url,
                    );
                }
            }
        }

        return $out;
    }

    /** rewards 가 문자열 배열이든 {name,count} 배열이든 쉼표 텍스트로 정규화. */
    private function normalizeRewards(mixed $rewards): ?string
    {
        if (is_string($rewards)) {
            return $rewards !== '' ? $rewards : null;
        }
        if (! is_array($rewards)) {
            return null;
        }

        $parts = [];
        foreach ($rewards as $r) {
            if (is_string($r)) {
                $parts[] = $r;
            } elseif (is_array($r)) {
                $name = $r['name'] ?? null;
                $count = $r['count'] ?? $r['amount'] ?? null;
                if ($name) {
                    $parts[] = $count ? "{$name} x{$count}" : $name;
                }
            }
        }
        $parts = array_filter(array_map('trim', $parts));

        return $parts !== [] ? implode(', ', array_slice($parts, 0, 6)) : null;
    }
}
