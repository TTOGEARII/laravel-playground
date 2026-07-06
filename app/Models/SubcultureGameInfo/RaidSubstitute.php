<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 레이드별 대체 캐릭터 관계(상위 캐릭터 ↔ 대체 캐릭터).
 * source='manual' 행은 커뮤니티 추출 sync 에도 보존된다(RaidParty 와 동일 원칙).
 */
class RaidSubstitute extends Model
{
    protected $table = 'subculture_raid_substitutes';

    protected $fillable = [
        'raid_id',
        'character_id',
        'substitute_character_id',
        'note',
        'source',
        'source_url',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function raid(): BelongsTo
    {
        return $this->belongsTo(Raid::class, 'raid_id');
    }

    /** 상위(원) 캐릭터. */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    /** 대체 캐릭터. */
    public function substituteCharacter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'substitute_character_id');
    }
}
