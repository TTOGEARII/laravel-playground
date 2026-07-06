<?php

namespace App\Services\SubcultureGameInfo\Raids\AlternativeParties;

use RuntimeException;

/**
 * 몰루로그 랭킹 API(ranks.baql.net)의 application/x-protobuf 응답 전용 경량 디코더.
 * composer 에 protobuf 의존성을 추가하는 대신 아래 고정 스키마만 손으로 파싱한다.
 *
 * message RankResponse { int64 total_count = 1; repeated Rank ranks = 2; }
 * message Rank { int64 score = 1; int32 rank = 2; int32 final_rank = 3; repeated Party parties = 4; }
 * message Party { repeated StudentSlot students = 1; }
 * message StudentSlot { oneof slot { Student student = 1; EmptySlot empty = 2; } }
 * message EmptySlot {}
 * message Student { string uid = 1; int32 level = 2; int32 tier = 3; int32 weapon_tier = 4; bool is_assist = 5; }
 *
 * 스키마와 어긋난 바이너리는 RuntimeException 을 던진다(호출부에서 로그 + 폴백).
 */
class MollulogRankDecoder
{
    /**
     * @return array{total_count: int, ranks: list<array{score: int, rank: int, final_rank: int, parties: list<list<?array{uid: string, level: int, tier: int, weapon_tier: int, is_assist: bool}>>}>}
     */
    public function decode(string $binary): array
    {
        $totalCount = 0;
        $ranks = [];

        foreach ($this->fields($binary) as [$field, $wire, $value]) {
            if ($field === 1 && $wire === 0) {
                $totalCount = (int) $value;
            } elseif ($field === 2 && $wire === 2) {
                $ranks[] = $this->decodeRank((string) $value);
            }
        }

        return ['total_count' => $totalCount, 'ranks' => $ranks];
    }

    /** Rank 메시지: score/rank/final_rank + 편성(웨이브) 목록. */
    private function decodeRank(string $binary): array
    {
        $rank = ['score' => 0, 'rank' => 0, 'final_rank' => 0, 'parties' => []];

        foreach ($this->fields($binary) as [$field, $wire, $value]) {
            match (true) {
                $field === 1 && $wire === 0 => $rank['score'] = (int) $value,
                $field === 2 && $wire === 0 => $rank['rank'] = (int) $value,
                $field === 3 && $wire === 0 => $rank['final_rank'] = (int) $value,
                $field === 4 && $wire === 2 => $rank['parties'][] = $this->decodeParty((string) $value),
                default => null, // 미지의 필드는 전방 호환을 위해 무시
            };
        }

        return $rank;
    }

    /** Party 메시지: StudentSlot 목록(빈 슬롯은 null). */
    private function decodeParty(string $binary): array
    {
        $slots = [];

        foreach ($this->fields($binary) as [$field, $wire, $value]) {
            if ($field === 1 && $wire === 2) {
                $slots[] = $this->decodeSlot((string) $value);
            }
        }

        return $slots;
    }

    /** StudentSlot(oneof): student 필드가 있으면 학생, empty 면 null. */
    private function decodeSlot(string $binary): ?array
    {
        foreach ($this->fields($binary) as [$field, $wire, $value]) {
            if ($field === 1 && $wire === 2) {
                return $this->decodeStudent((string) $value);
            }
        }

        return null; // empty(field 2) 또는 필드 없음 = 빈 슬롯
    }

    private function decodeStudent(string $binary): array
    {
        $student = ['uid' => '', 'level' => 0, 'tier' => 0, 'weapon_tier' => 0, 'is_assist' => false];

        foreach ($this->fields($binary) as [$field, $wire, $value]) {
            match (true) {
                $field === 1 && $wire === 2 => $student['uid'] = (string) $value,
                $field === 2 && $wire === 0 => $student['level'] = (int) $value,
                $field === 3 && $wire === 0 => $student['tier'] = (int) $value,
                $field === 4 && $wire === 0 => $student['weapon_tier'] = (int) $value,
                $field === 5 && $wire === 0 => $student['is_assist'] = $value !== 0,
                default => null,
            };
        }

        return $student;
    }

    /**
     * 메시지 바이너리를 (필드번호, wire type, 값) 목록으로 푼다.
     * wire 0=varint(int), 2=length-delimited(string). 1/5(fixed64/32)는 스킵용으로만 읽는다.
     *
     * @return list<array{0: int, 1: int, 2: int|string}>
     */
    private function fields(string $binary): array
    {
        $offset = 0;
        $length = strlen($binary);
        $fields = [];

        while ($offset < $length) {
            $tag = $this->varint($binary, $offset, $length);
            $field = $tag >> 3;
            $wire = $tag & 0x07;

            $value = match ($wire) {
                0 => $this->varint($binary, $offset, $length),
                1 => $this->bytes($binary, $offset, $length, 8),
                2 => $this->bytes($binary, $offset, $length, $this->varint($binary, $offset, $length)),
                5 => $this->bytes($binary, $offset, $length, 4),
                default => throw new RuntimeException("지원하지 않는 wire type: {$wire} (offset {$offset})"),
            };

            $fields[] = [$field, $wire, $value];
        }

        return $fields;
    }

    /** varint 하나를 읽고 offset 을 전진시킨다(최대 10바이트 = 64bit). */
    private function varint(string $binary, int &$offset, int $length): int
    {
        $result = 0;
        $shift = 0;

        while (true) {
            if ($offset >= $length) {
                throw new RuntimeException('varint 를 읽는 중 바이너리가 끝났습니다');
            }
            if ($shift > 63) {
                throw new RuntimeException('varint 가 64bit 를 초과합니다');
            }

            $byte = ord($binary[$offset++]);
            $result |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
    }

    /** length-delimited/fixed 페이로드를 읽고 offset 을 전진시킨다. */
    private function bytes(string $binary, int &$offset, int $length, int $count): string
    {
        if ($count < 0 || $offset + $count > $length) {
            throw new RuntimeException("선언된 길이({$count})가 바이너리 범위를 벗어납니다 (offset {$offset})");
        }

        $value = substr($binary, $offset, $count);
        $offset += $count;

        return $value;
    }
}
