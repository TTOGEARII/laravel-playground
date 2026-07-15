<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 외부 유저 접속 로그 1건(방문 페이지·유입경로·기기·UA·IP).
 * created_at 만 쓰므로 updated_at 은 비활성화.
 */
class AccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ip', 'device', 'method', 'path', 'referrer', 'user_agent', 'user_id', 'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
