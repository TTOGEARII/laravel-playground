<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 호요버스 — hoyo-codes.seria.moe 비공식 API(매일 HoYoLAB 교환으로 유효성 재검증).
 * 응답: { codes:[{code,status:OK/NOT_OK,rewards,game}] }. OK→사용가능, NOT_OK→만료.
 * ennead 와 교차검증 용도(같은 코드 2개 출처 → corroboration↑).
 */
class SeriaApiDriver extends AbstractSourceDriver
{
    public function driverKey(): string
    {
        return 'seria';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $cfg = config('subculture-game-info.drivers.seria');
        $game = $cfg['games'][$gameSlug] ?? null;
        if ($game === null) {
            return [];
        }

        $url = rtrim($cfg['base'], '/').'/codes';
        $json = $this->getJson($url, ['game' => $game]);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        foreach ($json['codes'] ?? [] as $entry) {
            $code = $entry['code'] ?? null;
            if (! is_string($code) || trim($code) === '') {
                continue;
            }
            $status = ($entry['status'] ?? '') === 'OK' ? CodeStatus::Active : CodeStatus::Expired;
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

        return $out;
    }
}
