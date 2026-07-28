<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 웹푸시 구독(브라우저 단위). 리딤코드·행사 캘린더 알림에 사용.
 * topics(json) 로 알림 주제를 구독별로 선택 — null 은 전체 수신(하위호환).
 */
class PushSubscription extends Model
{
    /** 선택 가능한 알림 주제(프론트 토글·검증의 단일 출처). */
    public const TOPICS = ['redeem', 'concert', 'event'];

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh_key',
        'auth_key',
        'endpoint_hash',
        'topics',
    ];

    protected function casts(): array
    {
        return ['topics' => 'array'];
    }

    /** 이 구독이 해당 주제 알림을 원하는가(null = 전체 수신). */
    public function wantsTopic(string $topic): bool
    {
        return $this->topics === null || in_array($topic, $this->topics, true);
    }

    /** 주제 수신 구독만 — null(전체 수신) 포함. */
    public function scopeForTopic(Builder $query, string $topic): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('topics')->orWhereJsonContains('topics', $topic));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
