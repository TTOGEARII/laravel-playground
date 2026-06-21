<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $table = 'chat_sessions';

    protected $fillable = ['user_id', 'chat_character_id', 'conversation_summary', 'summarized_until_message_id', 'affinity'];

    /**
     * 신규 세션 인스턴스의 기본 호감도 (DB 기본값과 일치 — create 직후 모델이 null로 보이는 문제 방지).
     */
    protected $attributes = ['affinity' => 10];

    protected function casts(): array
    {
        return ['affinity' => 'integer'];
    }

    public function chatCharacter(): BelongsTo
    {
        return $this->belongsTo(ChatCharacter::class, 'chat_character_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_session_id')->orderBy('created_at');
    }
}
