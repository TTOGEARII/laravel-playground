<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 레이드별 추천 편성(파티) 1개. source='manual' 은 크롤 sync 에도 보존된다.
 */
class RaidParty extends Model
{
    protected $table = 'subculture_raid_parties';

    protected $fillable = [
        'subculture_raid_id',
        'title',
        'difficulty',
        'sort',
        'source',
        'source_url',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function raid(): BelongsTo
    {
        return $this->belongsTo(Raid::class, 'subculture_raid_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(RaidPartyMember::class, 'subculture_raid_party_id')->orderBy('sort');
    }
}
