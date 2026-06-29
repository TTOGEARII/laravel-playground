<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 호요버스(원신/스타레일/젠레스) — ennead.cc 비공식 집계 JSON.
 * 응답: { active:[{code,rewards:[...]}], inactive:[...] }. active→사용가능, inactive→만료.
 * 보상(rewards) 포함. 만료일 자체는 제공 안 함(active 여부로 사용가능 판단).
 */
class EnneadApiDriver extends AbstractSourceDriver
{
    public function driverKey(): string
    {
        return 'ennead';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.ennead');
        $segment = $cfg['games'][$gameSlug] ?? null;
        if ($segment === null) {
            return [];
        }

        $url = rtrim($cfg['base'], '/')."/{$segment}/codes";
        $json = $this->getJson($url);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach (['active' => CodeStatus::Active, 'inactive' => CodeStatus::Expired] as $bucket => $status) {
            foreach ($json[$bucket] ?? [] as $entry) {
                $code = is_array($entry) ? ($entry['code'] ?? null) : (is_string($entry) ? $entry : null);
                if (! is_string($code) || trim($code) === '') {
                    continue;
                }
                $out[] = new CollectedCodeDto(
                    gameSlug: $gameSlug,
                    code: trim($code),
                    sourceType: SourceType::Aggregator,
                    source: $this->driverKey(),
                    region: CodeRegion::Global,
                    rewards: $this->normalizeRewards($entry['rewards'] ?? null),
                    status: $status,
                    sourceUrl: $url,
                );
            }
        }

        return $out;
    }
}
