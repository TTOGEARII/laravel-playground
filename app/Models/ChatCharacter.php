<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatCharacter extends Model
{
    protected $table = 'chat_characters';

    protected $fillable = [
        'name',
        'short_intro',
        'character_detail',
        'speech_style',
        'intro_message',
        'genre',
        'target',
        'image_path',
        'accent',
    ];

    /**
     * API/뷰용 이미지 URL.
     * - images/chat-bot/character/... → public 경로
     * - 기존 storage/characters/... → storage 링크 경로 (호환)
     */
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }
        $path = ltrim($this->image_path, '/');
        return str_starts_with($path, 'images/')
            ? asset($path)
            : asset('storage/' . $path);
    }

    /**
     * 뷰에서 사용할 배열 형태 (id, name, description, image, accent).
     */
    public function toCharacterArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $this->short_intro . ($this->character_detail ? ' ' . \Str::limit($this->character_detail, 80) : ''),
            'image' => $this->image_url ?? '',
            'accent' => $this->accent ?? 'accent-violet',
            'speech_style' => $this->speech_style ?? '',
            'intro' => $this->intro_message ?? '',
        ];
    }
}
