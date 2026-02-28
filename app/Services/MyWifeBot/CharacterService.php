<?php

namespace App\Services\MyWifeBot;

use App\Models\ChatCharacter;
use App\Services\Gemini\ChatService;
use App\Services\HelperService;
use Illuminate\Http\UploadedFile;

class CharacterService
{
    /** public 아래 이미지 저장 경로 (DB에는 이 상대 경로 저장) */
    private const IMAGE_DIR = 'images/chat-bot/character';

    public function __construct(
        private HelperService $helper,
        private ChatService $chatService
    ) {}

    /**
     * 캐릭터 추가.
     *
     * @param  array{character_name: string, short_intro: string, character_detail?: string|null, genre: string, target: string}  $data
     */
    public function add(array $data, ?UploadedFile $imageFile = null): ChatCharacter
    {
        $imagePath = null;
        if ($imageFile) {
            $imagePath = $this->helper->saveImage($imageFile, self::IMAGE_DIR, 'char_');
        }

        $intro = isset($data['intro_message']) && (string) $data['intro_message'] !== ''
            ? (string) $data['intro_message']
            : null;

        $character = ChatCharacter::create([
            'name' => $data['character_name'],
            'short_intro' => $data['short_intro'],
            'character_detail' => $data['character_detail'] ?? null,
            'speech_style' => $data['speech_style'] ?? null,
            'intro_message' => $intro,
            'genre' => $data['genre'],
            'target' => $data['target'],
            'image_path' => $imagePath,
        ]);

        if ($intro === null) {
            $generated = $this->chatService->generateGreeting($character);
            $character->update(['intro_message' => $generated]);
        }

        return $character->fresh();
    }

    /**
     * 캐릭터 수정.
     *
     * @param  array{character_name: string, short_intro: string, character_detail?: string|null, genre: string, target: string}  $data
     */
    public function update(ChatCharacter $character, array $data, ?UploadedFile $imageFile = null): void
    {
        $imagePath = $character->image_path;

        if ($imageFile) {
            $this->helper->deleteImage($character->image_path);
            $imagePath = $this->helper->saveImage($imageFile, self::IMAGE_DIR, 'char_');
        }

        $intro = isset($data['intro_message']) && (string) $data['intro_message'] !== ''
            ? (string) $data['intro_message']
            : null;

        if ($intro === null) {
            $intro = $this->chatService->generateGreeting($character);
        }

        $character->update([
            'name' => $data['character_name'],
            'short_intro' => $data['short_intro'],
            'character_detail' => $data['character_detail'] ?? null,
            'speech_style' => $data['speech_style'] ?? null,
            'intro_message' => $intro,
            'genre' => $data['genre'],
            'target' => $data['target'],
            'image_path' => $imagePath,
        ]);
    }

    /**
     * 캐릭터 삭제.
     */
    public function remove(ChatCharacter $character): void
    {
        $this->helper->deleteImage($character->image_path);
        $character->delete();
    }

    /**
     * 장르 옵션 (폼용).
     *
     * @return array<string, string>
     */
    public function getGenres(): array
    {
        return [
            'romance' => '로맨스',
            'fantasy' => '판타지',
            'action' => '액션',
            'slice_of_life' => '일상',
            'otaku' => '오타쿠/서브컬처',
        ];
    }

    /**
     * 타겟 옵션 (폼용).
     *
     * @return array<string, string>
     */
    public function getTargets(): array
    {
        return [
            'all' => '전체',
            'male' => '남성',
            'female' => '여성',
            'teen' => '10대',
        ];
    }
}
