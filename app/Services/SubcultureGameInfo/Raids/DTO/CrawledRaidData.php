<?php

namespace App\Services\SubcultureGameInfo\Raids\DTO;

use Carbon\Carbon;

/**
 * 크롤/수동 입력 레이드 회차 1건(+추천 편성 목록).
 */
final readonly class CrawledRaidData
{
    /**
     * @param  CrawledPartyData[]  $parties
     */
    public function __construct(
        public string $externalKey,
        public string $name,
        public ?string $bossName,
        public ?string $raidType,
        public ?array $tags,
        public ?Carbon $startsAt,
        public ?Carbon $endsAt,
        public ?string $sourceUrl,
        public array $parties,
    ) {}

    /** name 누락이면 null. external_key 가 없으면 종류|보스|시작일 해시로 파생한다. */
    public static function fromArray(array $data): ?self
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $startsAt = self::parseDate($data['starts_at'] ?? null);
        $endsAt = self::parseDate($data['ends_at'] ?? null);
        // 종료가 날짜만 오면(시각 00:00) 그날 끝까지 진행 중으로 본다.
        // (몰루로그 등은 표시상 마지막 날짜만 주는데, 실제 종료는 그날 새벽~오전이라
        //  00:00 기준으로는 마지막 날 내내 '종료'로 잘못 표시된다)
        if ($endsAt !== null && $endsAt->isStartOfDay()) {
            $endsAt = $endsAt->endOfDay();
        }

        $externalKey = trim((string) ($data['external_key'] ?? ''));
        if ($externalKey === '') {
            $externalKey = 'h-'.md5(implode('|', [
                (string) ($data['raid_type'] ?? ''),
                (string) ($data['boss_name'] ?? $name),
                $startsAt?->toDateString() ?? '',
            ]));
        }

        $parties = [];
        foreach ((array) ($data['parties'] ?? []) as $party) {
            if (! is_array($party)) {
                continue;
            }
            $dto = CrawledPartyData::fromArray($party);
            if ($dto !== null) {
                $parties[] = $dto;
            }
        }

        return new self(
            externalKey: $externalKey,
            name: $name,
            bossName: isset($data['boss_name']) ? (string) $data['boss_name'] : null,
            raidType: isset($data['raid_type']) ? (string) $data['raid_type'] : null,
            tags: is_array($data['tags'] ?? null) ? $data['tags'] : null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            sourceUrl: isset($data['source_url']) ? (string) $data['source_url'] : null,
            parties: $parties,
        );
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value, config('app.timezone', 'Asia/Seoul'));
        } catch (\Throwable) {
            return null;
        }
    }
}
