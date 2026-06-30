@extends('layouts.app')

@section('title', '문의하기 | Laravel Playland')

@section('body-class', 'inquiry-page')

@section('content')
    <div class="inquiry">
        <div class="inquiry-head">
            <a href="{{ url('/') }}" class="inquiry-back">← 홈으로</a>
            <h1 class="inquiry-title">문의하기</h1>
            <p class="inquiry-desc">
                일반 문의·버그 제보·기능 요청을 남겨 주세요. 답변이 필요하면 연락처를 함께 적어 주시면 됩니다.
            </p>
        </div>

        @if (session('inquiry_success'))
            <div class="inquiry-alert inquiry-alert-success" role="status">
                {{ session('inquiry_success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('inquiry.store') }}" class="inquiry-form" novalidate>
            @csrf

            <div class="inquiry-field">
                <label for="category">문의 유형</label>
                <select id="category" name="category" @class(['has-error' => $errors->has('category')])>
                    @foreach ($categories as $category)
                        <option value="{{ $category->value }}" @selected(old('category', 'general') === $category->value)>
                            {{ $category->label() }}
                        </option>
                    @endforeach
                </select>
                @error('category')
                    <p class="inquiry-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="inquiry-field">
                <label for="name">이름 / 닉네임</label>
                <input type="text" id="name" name="name" maxlength="50"
                    value="{{ old('name', auth()->user()?->name) }}"
                    placeholder="표시할 이름" @class(['has-error' => $errors->has('name')]) required>
                @error('name')
                    <p class="inquiry-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="inquiry-field">
                <label for="contact">연락처 <span class="inquiry-optional">(선택)</span></label>
                <input type="text" id="contact" name="contact" maxlength="120"
                    value="{{ old('contact') }}"
                    placeholder="답변받을 이메일·디스코드 등 (미입력 시 답변이 어려울 수 있어요)"
                    @class(['has-error' => $errors->has('contact')])>
                @error('contact')
                    <p class="inquiry-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="inquiry-field">
                <label for="subject">제목</label>
                <input type="text" id="subject" name="subject" maxlength="120"
                    value="{{ old('subject') }}" placeholder="무엇에 대한 문의인가요?"
                    @class(['has-error' => $errors->has('subject')]) required>
                @error('subject')
                    <p class="inquiry-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="inquiry-field">
                <label for="message">내용</label>
                <textarea id="message" name="message" rows="7" maxlength="2000"
                    placeholder="자세한 내용을 적어 주세요. (최소 10자)"
                    @class(['has-error' => $errors->has('message')]) required>{{ old('message') }}</textarea>
                @error('message')
                    <p class="inquiry-error">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="inquiry-submit">문의 보내기</button>
        </form>
    </div>
@endsection
