<?php

namespace App\Models\SubcultureGameInfo;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 내 풀 조합에서 미보유 캐릭터에 사용자가 지정한 대체 캐릭터 매핑.
 * 로그인 전용(비로그인은 localStorage, 동일 { character_key: substitute_key } 계약).
 */
class UserSubstitute extends Model
{
    protected $table = 'subculture_user_substitutes';

    protected $fillable = [
        'user_id',
        'subculture_game_id',
        'character_key',
        'substitute_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }
}
