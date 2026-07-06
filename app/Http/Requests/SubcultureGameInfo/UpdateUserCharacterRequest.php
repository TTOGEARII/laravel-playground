<?php

namespace App\Http\Requests\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Character;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 내 캐릭터 보유/성장도 저장 검증.
 * growth 규칙은 config raids.growth_fields 의 게임별 정의에서 동적으로 만든다
 * (select → in:옵션, number → integer|min|max, 정의 밖 키 금지).
 */
class UpdateUserCharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        /** @var Character $character */
        $character = $this->route('character');
        $fields = config("subculture-game-info.raids.growth_fields.{$character->game?->slug}", []);

        $rules = [
            'owned' => ['required', 'boolean'],
            'growth' => ['nullable', 'array:'.implode(',', array_column($fields, 'key'))],
        ];

        foreach ($fields as $field) {
            $rules["growth.{$field['key']}"] = match ($field['type']) {
                'select' => ['nullable', Rule::in($field['options'])],
                'number' => ['nullable', 'integer', "min:{$field['min']}", "max:{$field['max']}"],
                default => ['nullable', 'string', 'max:50'],
            };
        }

        return $rules;
    }
}
