<?php

namespace App\Models\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * 행사 캘린더 이벤트(내한공연·동인행사·기업행사 통합). (source, external_key)가 동일성 키.
 */
class Event extends Model
{
    protected $table = 'calendar_events';

    protected $fillable = [
        'source',
        'external_key',
        'kind',
        'genre',
        'title',
        'starts_on',
        'ends_on',
        'time_text',
        'venue',
        'price_text',
        'ticket_open_text',
        'ticket_links',
        'extra',
        'poster_url',
        'poster_path',
        'detail_url',
        'active_flg',
    ];

    protected function casts(): array
    {
        return [
            'kind' => EventKind::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ticket_links' => 'array',
            'extra' => 'array',
            'active_flg' => 'boolean',
        ];
    }

    /** 표시용 포스터 URL — storage 캐시 우선, 없으면 원본 폴백(출처 고지와 함께 사용). */
    protected function displayPosterUrl(): Attribute
    {
        return Attribute::get(fn () => $this->poster_path
            ? Storage::disk('public')->url($this->poster_path)
            : $this->poster_url);
    }
}
