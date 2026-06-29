<?php

namespace App\Models\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedeemCode extends Model
{
    protected $table = 'redeem_codes';

    protected $fillable = [
        'subculture_game_id',
        'code',
        'region',
        'rewards',
        'source',
        'source_type',
        'source_url',
        'status',
        'found_at',
        'expires_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'region' => CodeRegion::class,
            'source_type' => SourceType::class,
            'status' => CodeStatus::class,
            'found_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    /** 만료 시각이 지났거나 상태가 만료면 true. */
    protected function isExpired(): Attribute
    {
        return Attribute::get(fn () => $this->status === CodeStatus::Expired
            || ($this->expires_at !== null && $this->expires_at->isPast()));
    }

    /** 만료되지 않은(사용 가능/미검증) 코드. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', '!=', CodeStatus::Expired->value)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /** 메인(정리 사이트) 소스만. */
    public function scopeMain(Builder $query): Builder
    {
        return $query->where('source_type', SourceType::Aggregator->value);
    }

    /** 커뮤니티(보조 신호) 소스만. */
    public function scopeCommunity(Builder $query): Builder
    {
        return $query->where('source_type', SourceType::Community->value);
    }
}
