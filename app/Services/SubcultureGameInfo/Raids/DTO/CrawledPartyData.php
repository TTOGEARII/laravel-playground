<?php

namespace App\Services\SubcultureGameInfo\Raids\DTO;

/**
 * 크롤/수동 입력 추천 편성 1개. members 는 캐릭터 참조 배열
 * (각 원소: external_key, name, slot_type?, sort?, note? — 성장 스펙 등 짧은 메모).
 */
final readonly class CrawledPartyData
{
    /**
     * @param  array<int, array{external_key: string, name: string, slot_type: ?string, sort: int, note: ?string}>  $members
     */
    public function __construct(
        public ?string $title,
        public ?string $difficulty,
        public int $sort,
        public ?string $sourceUrl,
        public ?string $note,
        public array $members,
    ) {}

    /** 멤버가 하나도 없으면 null — 빈 편성은 저장하지 않는다. */
    public static function fromArray(array $data): ?self
    {
        $members = [];
        foreach ((array) ($data['members'] ?? []) as $i => $member) {
            if (! is_array($member)) {
                continue;
            }
            $externalKey = trim((string) ($member['external_key'] ?? ''));
            $name = trim((string) ($member['name'] ?? ''));
            if ($externalKey === '' && $name === '') {
                continue;
            }
            $members[] = [
                'external_key' => $externalKey,
                'name' => $name,
                'slot_type' => isset($member['slot_type']) ? (string) $member['slot_type'] : null,
                'sort' => (int) ($member['sort'] ?? $i),
                'note' => isset($member['note']) && trim((string) $member['note']) !== '' ? (string) $member['note'] : null,
            ];
        }
        if ($members === []) {
            return null;
        }

        return new self(
            title: isset($data['title']) ? (string) $data['title'] : null,
            difficulty: isset($data['difficulty']) ? (string) $data['difficulty'] : null,
            sort: (int) ($data['sort'] ?? 0),
            sourceUrl: isset($data['source_url']) ? (string) $data['source_url'] : null,
            note: isset($data['note']) ? (string) $data['note'] : null,
            members: $members,
        );
    }
}
