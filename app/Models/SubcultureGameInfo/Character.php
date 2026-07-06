<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 게임별 캐릭터 마스터. (게임, external_key)가 동일성 키.
 * 게임별 속성은 traits JSON 자유 스키마(블아: 지형적성/공격타입, 니케: 버스트/무기 등).
 */
class Character extends Model
{
    protected $table = 'subculture_characters';

    protected $fillable = [
        'subculture_game_id',
        'external_key',
        'name',
        'rarity',
        'traits',
        'image_url',
        'image_path',
        'source',
        'source_url',
        'active_flg',
    ];

    protected function casts(): array
    {
        return [
            'traits' => 'array',
            'active_flg' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    /** 화면 노출용 이미지 URL — 로컬 캐시가 있으면 캐시, 없으면 원본(외부) 폴백. */
    protected function displayImageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : $this->image_url);
    }

    /** 노출 대상(활성) 캐릭터만. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_flg', true);
    }
}
