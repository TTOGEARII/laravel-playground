<?php

namespace App\Services\MyWifeBot;

use App\Enums\MyWifeBot\Genre;
use App\Enums\MyWifeBot\Target;
use App\Models\ChatCharacter;
use App\Services\Gemini\ChatService;
use App\Services\HelperService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

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

        $character = ChatCharacter::create(array_merge(
            $this->personaAttributes($data),
            [
                'user_id' => Auth::id(),
                'intro_message' => $intro,
                'image_path' => $imagePath,
            ]
        ));

        if ($intro === null) {
            $generated = $this->chatService->generateGreeting($character);
            $character->update(['intro_message' => $generated]);
        }

        return $character->fresh() ?? $character;
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

        // 인사말 자동 생성이 수정 전 페르소나를 참조하지 않도록, 새 속성을 먼저 반영한다.
        $character->fill($this->personaAttributes($data));

        $intro = isset($data['intro_message']) && (string) $data['intro_message'] !== ''
            ? (string) $data['intro_message']
            : null;

        if ($intro === null) {
            $intro = $this->chatService->generateGreeting($character);
        }

        $character->fill([
            'intro_message' => $intro,
            'image_path' => $imagePath,
        ])->save();
    }

    /**
     * 폼 입력을 모델 속성으로 매핑 (추가/수정 공통). 페르소나·배경 설정 포함.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personaAttributes(array $data): array
    {
        return [
            'name' => $data['character_name'],
            'short_intro' => $data['short_intro'],
            'character_detail' => $data['character_detail'] ?? null,
            'personality' => $data['personality'] ?? null,
            'appearance' => $data['appearance'] ?? null,
            'likes' => $data['likes'] ?? null,
            'dislikes' => $data['dislikes'] ?? null,
            'user_alias' => $data['user_alias'] ?? null,
            'example_dialogue' => $data['example_dialogue'] ?? null,
            'world_setting' => $data['world_setting'] ?? null,
            'relationships' => $data['relationships'] ?? null,
            'user_persona' => $data['user_persona'] ?? null,
            'speech_style' => $data['speech_style'] ?? null,
            'genre' => $data['genre'],
            'target' => $data['target'],
        ];
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
        return Genre::options();
    }

    /**
     * 타겟 옵션 (폼용).
     *
     * @return array<string, string>
     */
    public function getTargets(): array
    {
        return Target::options();
    }
}
