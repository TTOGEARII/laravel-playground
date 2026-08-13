<?php

namespace App\Models\MyWifeBot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMemory extends Model
{
    protected $table = 'chat_memories';

    protected $fillable = ['chat_session_id', 'chat_character_id', 'kind', 'content', 'embedding'];

    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }
}
