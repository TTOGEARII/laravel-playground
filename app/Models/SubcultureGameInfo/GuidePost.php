<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 커뮤니티 공략글 메타(디씨 개념글 · 아카 추천글). 본문은 저장하지 않는다.
 */
class GuidePost extends Model
{
    protected $table = 'subculture_guide_posts';

    protected $fillable = [
        'subculture_game_id',
        'subculture_raid_id',
        'source',
        'external_id',
        'title',
        'url',
        'posted_at',
        'views',
        'rate',
        'matched_keyword',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'views' => 'integer',
            'rate' => 'integer',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    public function raid(): BelongsTo
    {
        return $this->belongsTo(Raid::class, 'subculture_raid_id');
    }
}
