<?php

namespace App\Models\SubcultureGameInfo;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 로그인 사용자의 캐릭터 풀(보유 + 성장도). 비로그인은 localStorage 를 쓴다.
 * growth JSON 스키마는 config raids.growth_fields 의 게임별 정의를 따른다.
 */
class UserCharacter extends Model
{
    protected $table = 'subculture_user_characters';

    protected $fillable = [
        'user_id',
        'subculture_character_id',
        'owned_flg',
        'growth',
    ];

    protected function casts(): array
    {
        return [
            'owned_flg' => 'boolean',
            'growth' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'subculture_character_id');
    }
}
