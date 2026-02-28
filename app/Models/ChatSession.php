<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $table = 'chat_sessions';

    protected $fillable = ['chat_character_id', 'conversation_summary', 'summarized_until_message_id'];

    public function chatCharacter(): BelongsTo
    {
        return $this->belongsTo(ChatCharacter::class, 'chat_character_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_session_id')->orderBy('created_at');
    }
}
