<?php

namespace App\Models\SubcultureGameInfo\Agent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 에이전트 대화 메시지(user|assistant). assistant 는 tool_calls·cards 를 함께 저장.
 */
class AgentMessage extends Model
{
    protected $table = 'subculture_agent_messages';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'tool_calls',
        'cards',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'cards' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'session_id');
    }
}
