<?php

namespace App\Services\SubcultureGameInfo\Raids\DTO;

/**
 * 사이드카 크롤 결과(캐릭터 1명). JSON 계약: tools/raid-crawler 어댑터 출력과 1:1.
 */
final readonly class CrawledCharacterData
{
    public function __construct(
        public string $externalKey,
        public string $name,
        public ?string $rarity = null,
        public ?array $traits = null,
        public ?string $imageUrl = null,
        public ?string $sourceUrl = null,
    ) {}

    /** 필수 키(external_key, name) 누락이면 null — 호출부가 로그 후 스킵한다. */
    public static function fromArray(array $data): ?self
    {
        $externalKey = trim((string) ($data['external_key'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($externalKey === '' || $name === '') {
            return null;
        }

        return new self(
            externalKey: $externalKey,
            name: $name,
            rarity: isset($data['rarity']) ? (string) $data['rarity'] : null,
            traits: is_array($data['traits'] ?? null) ? $data['traits'] : null,
            imageUrl: isset($data['image_url']) ? (string) $data['image_url'] : null,
            sourceUrl: isset($data['source_url']) ? (string) $data['source_url'] : null,
        );
    }
}
