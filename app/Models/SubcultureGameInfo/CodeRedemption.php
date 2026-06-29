<?php

namespace App\Models\SubcultureGameInfo;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 로그인 사용자가 특정 리딤코드를 "교환 완료"로 표시한 기록.
 */
class CodeRedemption extends Model
{
    protected $table = 'redeem_code_redemptions';

    protected $fillable = [
        'user_id',
        'redeem_code_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(RedeemCode::class, 'redeem_code_id');
    }
}
