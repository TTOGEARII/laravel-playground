<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 속성(성격)별 추천 조합 — kind: curated(팀 매니저 큐레이션) / usage(트릭컬 레코드 시즌 실측 파생).
 */
class AttributeParty extends Model
{
    protected $table = 'subculture_attribute_parties';

    protected $fillable = [
        'subculture_game_id',
        'attribute',
        'kind',
        'source',
        'title',
        'source_url',
        'period',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(AttributePartyMember::class, 'attribute_party_id')->orderBy('sort');
    }
}
