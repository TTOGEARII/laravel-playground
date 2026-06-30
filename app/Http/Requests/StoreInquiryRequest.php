<?php

namespace App\Http\Requests;

use App\Enums\InquiryCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;  // 누구나 문의 가능
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(InquiryCategory::class)],
            'name' => ['required', 'string', 'max:50'],
            'contact' => ['nullable', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category' => '문의 유형',
            'name' => '이름',
            'contact' => '연락처',
            'subject' => '제목',
            'message' => '내용',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => '내용은 최소 :min자 이상 입력해 주세요.',
        ];
    }
}
