<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 게임 위키 항목(카테고리별 전체 수집). (게임, source, menu_key, external_key)가 동일성 키.
 * filters = 목록 필터 배지, detail = 상세 섹션(정규화).
 */
class WikiEntry extends Model
{
    protected $table = 'subculture_wiki_entries';

    protected $fillable = [
        'subculture_game_id',
        'source',
        'menu_key',
        'menu_label',
        'external_key',
        'name',
        'icon_url',
        'filters',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'detail' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'subculture_game_id');
    }

    public function scopeForGame(Builder $query, int $gameId): Builder
    {
        return $query->where('subculture_game_id', $gameId);
    }
}
