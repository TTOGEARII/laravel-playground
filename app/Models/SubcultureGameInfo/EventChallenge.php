<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 이벤트 챌린지 스테이지 공략(블아) — 아카 '올인원' 글에서 수집.
 */
class EventChallenge extends Model
{
    protected $table = 'subculture_event_challenges';

    protected $fillable = [
        'subculture_game_id',
        'event_key',
        'event_name',
        'starts_at',
        'ends_at',
        'stage_label',
        'stage_name',
        'clear_condition',
        'summary',
        'video_url',
        'extra_videos',
        'mentioned',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'mentioned' => 'array',
            'extra_videos' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }
}
