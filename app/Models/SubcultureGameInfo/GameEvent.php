<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 진행중/예정 컨텐츠(이벤트·스토리·레이드 예고). (게임, external_key)가 동일성 키.
 * scope=current(현재) | forecast(미래시). kind=event/raid/story…
 */
class GameEvent extends Model
{
    protected $table = 'subculture_events';

    protected $fillable = [
        'subculture_game_id',
        'external_key',
        'scope',
        'kind',
        'title',
        'starts_at',
        'ends_at',
        'image_url',
        'link_url',
        'source',
    ];

    protected function casts(): array
    {
        return [
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
