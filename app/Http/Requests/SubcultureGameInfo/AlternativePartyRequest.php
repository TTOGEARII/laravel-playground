<?php

namespace App\Http\Requests\SubcultureGameInfo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 미보유 캐릭터 제외 실전 편성 조회 검증.
 * exclude 는 미보유 캐릭터 external_key 배열(문자열) — 원본 랭킹 API 에 그대로 전달되므로 상한을 둔다.
 */
class AlternativePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 공개 API(레이드 상세와 동일)
    }

    public function rules(): array
    {
        return [
            'exclude' => ['nullable', 'array', 'max:500'],
            'exclude.*' => ['string', 'max:40'],
            'include' => ['nullable', 'array', 'max:6'], // 파티 슬롯 상 6명 초과 포함은 무의미
            'include.*' => ['string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // 블아 전용 난이도 필터(인세인/토먼트/루나틱) — 그 외 게임은 무시된다
            'difficulty' => ['nullable', 'string', 'in:insane,torment,lunatic'],
        ];
    }

    /** 난이도 필터(블아 전용). 미지정이면 null(전체). */
    public function difficulty(): ?string
    {
        return $this->validated('difficulty');
    }

    /** @return list<string> 중복 제거한 제외 캐릭터 external_key 목록 */
    public function excludeKeys(): array
    {
        return $this->uniqueKeys('exclude');
    }

    /** @return list<string> 중복 제거한 포함(필수) 캐릭터 external_key 목록 */
    public function includeKeys(): array
    {
        return $this->uniqueKeys('include');
    }

    /** @return list<string> */
    private function uniqueKeys(string $field): array
    {
        return collect($this->validated($field) ?? [])
            ->map(fn ($key) => (string) $key)
            ->unique()
            ->values()
            ->all();
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
