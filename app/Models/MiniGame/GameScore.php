<?php

namespace App\Models\MiniGame;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 미니게임 점수 1건(랭킹 항목). 비로그인 사용자도 남길 수 있어 user_id 는 nullable.
 */
class GameScore extends Model
{
    protected $fillable = [
        'game_key',
        'user_id',
        'nickname',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
