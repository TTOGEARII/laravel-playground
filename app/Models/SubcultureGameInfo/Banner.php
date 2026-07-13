<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 픽업 배너(모집중 학생). (게임, external_key)가 동일성 키.
 * scope=current(현재 모집) | forecast(미래시 예고).
 */
class Banner extends Model
{
    protected $table = 'subculture_banners';

    protected $fillable = [
        'subculture_game_id',
        'external_key',
        'scope',
        'kind',
        'title',
        'featured',
        'starts_at',
        'ends_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    /** upcoming(예정) | active(진행중) | ended(종료) — 기간 기준. */
    protected function status(): Attribute
    {
        return Attribute::get(function () {
            $now = now();
            if ($this->starts_at && $this->starts_at->isAfter($now)) {
                return 'upcoming';
            }
            if ($this->ends_at && $this->ends_at->isBefore($now)) {
                return 'ended';
            }

            return 'active';
        });
    }

    public function scopeForGame(Builder $query, int $gameId): Builder
    {
        return $query->where('subculture_game_id', $gameId);
    }
}
