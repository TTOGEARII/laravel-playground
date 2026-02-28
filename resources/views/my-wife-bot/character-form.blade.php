@extends('layouts.app')

@section('title', ($character ? '캐릭터 수정' : '챗봇 추가하기') . ' - MyWifeBot')

@section('body-class', 'my-wife-bot-form-page')

@section('content')
    <div class="my-wife-bot-form-wrapper">
        <header class="my-wife-bot-header-bar">
            <a href="{{ route('my-wife-bot.characters') }}" class="back-button">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                목록으로
            </a>
            <h1 class="my-wife-bot-header-title">{{ $character ? '캐릭터 수정' : '챗봇 추가하기' }}</h1>
        </header>

        <form action="{{ $character ? route('my-wife-bot.characters.update', $character) : route('my-wife-bot.characters.store') }}" method="POST" enctype="multipart/form-data" class="character-form">
            @csrf
            @if($character)
                @method('PUT')
            @endif

            <div class="form-group">
                <label for="character_image">캐릭터 이미지 <span class="form-optional">(선택)</span></label>
                <input type="file" id="character_image" name="character_image" accept=".png,.jpg,.jpeg,.webp" class="form-input-file">
                @if($character?->image_path)
                    <p class="form-hint">현재 이미지: <img src="{{ $character->image_url }}" alt="" class="form-current-image" /> (새 파일을 선택하면 교체됩니다)</p>
                @endif
                @error('character_image')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="character_name">캐릭터 이름 <span class="form-required">*</span> (2~30자)</label>
                <input type="text" id="character_name" name="character_name" value="{{ old('character_name', $character?->name) }}" maxlength="30" required class="form-input">
                @error('character_name')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="short_intro">한 줄 소개 <span class="form-required">*</span> (50자)</label>
                <input type="text" id="short_intro" name="short_intro" value="{{ old('short_intro', $character?->short_intro) }}" maxlength="50" required class="form-input">
                @error('short_intro')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="character_detail">캐릭터 상세 (1000자)</label>
                <textarea id="character_detail" name="character_detail" maxlength="1000" rows="6" class="form-textarea">{{ old('character_detail', $character?->character_detail) }}</textarea>
                @error('character_detail')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="speech_style">말투 설정 <span class="form-optional">(선택)</span></label>
                <textarea id="speech_style" name="speech_style" rows="3" class="form-textarea" placeholder="예: 반말을 쓰고, ~요로 끝낸다. 친근한 말투.">{{ old('speech_style', $character?->speech_style) }}</textarea>
                @error('speech_style')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="intro_message">인트로 <span class="form-optional">(비어 있으면 페르소나 기반으로 자동 생성)</span></label>
                <div class="form-intro-row">
                    <textarea id="intro_message" name="intro_message" maxlength="1000" rows="3" class="form-textarea" placeholder="대화 시작 시 캐릭터가 보낼 첫 메시지. 비워두면 저장 시 AI가 자동 생성합니다.">{{ old('intro_message', $character?->intro_message) }}</textarea>
                    @if($character)
                        <button type="button" class="form-btn-generate-intro" id="btn_generate_intro" data-url="{{ route('my-wife-bot.characters.generate-greeting', $character) }}" data-csrf="{{ csrf_token() }}">
                            AI로 인트로 생성
                        </button>
                    @endif
                </div>
                @error('intro_message')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="genre">장르</label>
                    <select id="genre" name="genre" class="form-select">
                        @foreach($genres as $value => $label)
                            <option value="{{ $value }}" {{ old('genre', $character?->genre) === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="target">타겟</label>
                    <select id="target" name="target" class="form-select">
                        @foreach($targets as $value => $label)
                            <option value="{{ $value }}" {{ old('target', $character?->target) === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="form-submit">{{ $character ? '수정하기' : '캐릭터 생성' }}</button>
                @if($character)
                    <a href="{{ route('my-wife-bot.characters') }}" class="form-cancel">취소</a>
                @endif
            </div>
        </form>
    </div>
@endsection

@push('styles')
<style>
.my-wife-bot-form-page .my-wife-bot-form-wrapper { margin-top: 0; }
.my-wife-bot-form-wrapper { max-width: 620px; margin: 0 auto; }
.my-wife-bot-header-bar {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 12px 0 24px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 32px;
}
.my-wife-bot-header-bar .back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
}
.my-wife-bot-header-bar .back-button:hover { background: rgba(255, 255, 255, 0.08); color: var(--text-primary); }
.my-wife-bot-header-bar .back-button svg { width: 16px; height: 16px; }
.my-wife-bot-header-title { font-size: 1.5rem; font-weight: 600; color: var(--text-primary); margin: 0; }
.character-form { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; padding: 28px; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-size: 0.875rem; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px; }
.form-required { color: #f87171; }
.form-optional { color: var(--text-secondary); font-weight: 400; }
.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 10px 14px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-primary);
    font-size: 0.9375rem;
    font-family: inherit;
}
.form-select {
    background-color: #1e293b;
    color: #f1f5f9;
    cursor: pointer;
}
.form-select option {
    background: #1e293b;
    color: #f1f5f9;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: rgba(139, 92, 246, 0.5);
}
.form-textarea { resize: vertical; min-height: 120px; }
.form-input-file { color: var(--text-secondary); font-size: 0.875rem; }
.form-hint { font-size: 0.8rem; color: var(--text-secondary); margin-top: 8px; }
.form-current-image { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; vertical-align: middle; margin-left: 4px; }
.form-error { font-size: 0.8rem; color: #f87171; margin-top: 6px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-actions { margin-top: 28px; display: flex; gap: 12px; align-items: center; }
.form-submit {
    padding: 12px 24px;
    background: linear-gradient(135deg, var(--accent-2), rgba(139, 92, 246, 0.85));
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 0.9375rem;
    font-weight: 500;
    cursor: pointer;
}
.form-submit:hover { opacity: 0.95; }
.form-cancel {
    padding: 12px 20px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.9375rem;
}
.form-cancel:hover { color: var(--text-primary); }
.form-intro-row { display: flex; flex-direction: column; gap: 12px; }
.form-btn-generate-intro {
    align-self: flex-start;
    padding: 8px 16px;
    background: rgba(139, 92, 246, 0.2);
    border: 1px solid rgba(139, 92, 246, 0.5);
    border-radius: 8px;
    color: var(--accent-2);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
}
.form-btn-generate-intro:hover { background: rgba(139, 92, 246, 0.3); }
.form-btn-generate-intro:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('btn_generate_intro');
    if (!btn) return;
    var url = btn.getAttribute('data-url');
    var csrf = btn.getAttribute('data-csrf');
    var textarea = document.getElementById('intro_message');
    btn.addEventListener('click', function() {
        btn.disabled = true;
        btn.textContent = '생성 중...';
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf
            }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.intro && textarea) textarea.value = data.intro;
        })
        .catch(function() {
            if (textarea) textarea.placeholder = '생성 실패. 직접 입력해 주세요.';
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = 'AI로 인트로 생성';
        });
    });
});
</script>
@endpush
