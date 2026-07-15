<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 외부 유저 접속 로그 1건(방문 페이지·유입경로·기기·UA·IP).
 * created_at 만 쓰므로 updated_at 은 비활성화. 개인정보처리방침에 따라 1년 후 자동 파기(Prunable).
 */
class AccessLog extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ip', 'device', 'method', 'path', 'referrer', 'user_agent', 'user_id', 'created_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 개인정보처리방침 명시대로 1년 지난 로그는 파기 대상(model:prune 스케줄). */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->subYear());
    }
}
