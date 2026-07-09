<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 웹푸시 구독(브라우저 단위). 새 리딤코드 등록 알림에 사용.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh_key',
        'auth_key',
        'endpoint_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
