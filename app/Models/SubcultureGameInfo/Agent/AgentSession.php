<?php

namespace App\Models\SubcultureGameInfo\Agent;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 서브컬쳐 AI 에이전트 대화 세션.
 */
class AgentSession extends Model
{
    protected $table = 'subculture_agent_sessions';

    protected $fillable = [
        'uuid',
        'user_id',
        'persona_kind',
        'persona_ref',
        'title',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->uuid ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    /** 라우트 모델 바인딩은 외부 노출 키(uuid)로. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'session_id')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
