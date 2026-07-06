<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 게임별 레이드(보스) 회차. tags JSON 에 지형/속성 등 게임별 부가 정보를 담는다.
 */
class Raid extends Model
{
    protected $table = 'subculture_raids';

    protected $fillable = [
        'subculture_game_id',
        'external_key',
        'name',
        'boss_name',
        'raid_type',
        'tags',
        'starts_at',
        'ends_at',
        'source',
        'source_url',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(RaidParty::class, 'subculture_raid_id')->orderBy('sort');
    }

    public function guidePosts(): HasMany
    {
        return $this->hasMany(GuidePost::class, 'subculture_raid_id');
    }

    /**
     * 진행 상태: upcoming(시작 전) / active(진행 중) / ended(종료).
     * 일정 정보가 없으면 active 로 취급해 목록에서 숨기지 않는다.
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            $now = now();
            if ($this->starts_at !== null && $now->lt($this->starts_at)) {
                return 'upcoming';
            }
            if ($this->ends_at !== null && $now->gt($this->ends_at)) {
                return 'ended';
            }

            return 'active';
        });
    }
}
