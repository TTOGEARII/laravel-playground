<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 편성 구성원(파티 ↔ 캐릭터 연결 + 슬롯/정렬 메타).
 */
class RaidPartyMember extends Model
{
    protected $table = 'subculture_raid_party_members';

    protected $fillable = [
        'subculture_raid_party_id',
        'subculture_character_id',
        'slot_type',
        'sort',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(RaidParty::class, 'subculture_raid_party_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'subculture_character_id');
    }
}
