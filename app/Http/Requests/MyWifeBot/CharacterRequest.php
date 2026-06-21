<?php

namespace App\Http\Requests\MyWifeBot;

use App\Enums\MyWifeBot\Genre;
use App\Enums\MyWifeBot\Target;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 캐릭터 추가/수정 공통 검증.
 * 권한(소유자) 검사는 컨트롤러에서 처리하므로 여기서는 통과시킨다.
 */
class CharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'character_name' => ['required', 'string', 'min:2', 'max:30'],
            'short_intro' => ['required', 'string', 'max:50'],
            'character_detail' => ['nullable', 'string', 'max:1000'],
            'personality' => ['nullable', 'string', 'max:500'],
            'appearance' => ['nullable', 'string', 'max:500'],
            'likes' => ['nullable', 'string', 'max:255'],
            'dislikes' => ['nullable', 'string', 'max:255'],
            'user_alias' => ['nullable', 'string', 'max:50'],
            'example_dialogue' => ['nullable', 'string', 'max:2000'],
            'world_setting' => ['nullable', 'string', 'max:2000'],
            'speech_style' => ['nullable', 'string'],
            'intro_message' => ['nullable', 'string', 'max:1000'],
            'genre' => ['required', Rule::enum(Genre::class)],
            'target' => ['required', Rule::enum(Target::class)],
            'character_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'character_name.required' => '캐릭터 이름을 입력하세요.',
            'character_name.min' => '캐릭터 이름은 2자 이상이어야 합니다.',
            'short_intro.required' => '한 줄 소개를 입력하세요.',
        ];
    }
}
