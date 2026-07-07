<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributePartyMember extends Model
{
    protected $table = 'subculture_attribute_party_members';

    protected $fillable = [
        'attribute_party_id',
        'subculture_character_id',
        'position',
        'sort',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'meta' => 'array',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(AttributeParty::class, 'attribute_party_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'subculture_character_id');
    }
}
