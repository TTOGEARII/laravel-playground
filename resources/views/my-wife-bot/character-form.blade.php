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

            <div class="form-ai-box">
                <label for="novel_source">📖 소설 정보로 자동 생성 <span class="form-optional">(선택)</span></label>
                <p class="form-hint">작품의 시놉시스·캐릭터 설명·세계관 등을 붙여넣고 버튼을 누르면, AI가 분석해 아래 항목들을 자동으로 채워줍니다.</p>
                <textarea id="novel_source" rows="5" class="form-textarea" placeholder="예) 『늑대와 향신료』 — 행상인 크래프트 로렌스는 어느 날 수레에서 풍요의 신 '현랑 호로'를 만난다. 호로는 늑대 귀와 꼬리를 가진 소녀의 모습으로..."></textarea>
                <button type="button" class="form-btn-analyze" id="btn_analyze_novel"
                        data-url="{{ route('my-wife-bot.characters.analyze') }}" data-csrf="{{ csrf_token() }}">
                    ✨ AI로 분석해 채우기
                </button>
                <span class="form-analyze-status" id="analyze_status"></span>
            </div>

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

            <div class="form-section-title">페르소나 (선택) — 채워질수록 캐릭터가 일관되게 행동합니다</div>

            <div class="form-group">
                <label for="personality">성격 <span class="form-optional">(선택, 500자)</span></label>
                <textarea id="personality" name="personality" maxlength="500" rows="3" class="form-textarea" placeholder="예: 도도하지만 정이 많다. 호기심이 강하고 장난기가 있다.">{{ old('personality', $character?->personality) }}</textarea>
                @error('personality')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-group">
                <label for="appearance">외모 <span class="form-optional">(선택, 500자)</span></label>
                <textarea id="appearance" name="appearance" maxlength="500" rows="3" class="form-textarea" placeholder="예: 은발에 붉은 눈동자, 늑대 귀와 꼬리를 가진 소녀.">{{ old('appearance', $character?->appearance) }}</textarea>
                @error('appearance')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="likes">좋아하는 것 <span class="form-optional">(선택)</span></label>
                    <input type="text" id="likes" name="likes" value="{{ old('likes', $character?->likes) }}" maxlength="255" class="form-input" placeholder="예: 사과, 술, 칭찬받는 것">
                    @error('likes')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div class="form-group">
                    <label for="dislikes">싫어하는 것 <span class="form-optional">(선택)</span></label>
                    <input type="text" id="dislikes" name="dislikes" value="{{ old('dislikes', $character?->dislikes) }}" maxlength="255" class="form-input" placeholder="예: 외로움, 거짓말">
                    @error('dislikes')<p class="form-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="form-group">
                <label for="user_alias">유저 호칭 <span class="form-optional">(선택, 캐릭터가 유저를 부르는 말)</span></label>
                <input type="text" id="user_alias" name="user_alias" value="{{ old('user_alias', $character?->user_alias) }}" maxlength="50" class="form-input" placeholder="예: 그대, 주인님, 자기야">
                @error('user_alias')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-group">
                <label for="example_dialogue">예시 대화 <span class="form-optional">(선택, 2000자 — 말투/성격을 가장 잘 학습시킵니다)</span></label>
                <textarea id="example_dialogue" name="example_dialogue" maxlength="2000" rows="5" class="form-textarea" placeholder="유저: 너 이름이 뭐야?&#10;캐릭터: 후후, 나는 현랑 호로다. 현명한 늑대지.&#10;&#10;유저: 사과 좋아해?&#10;캐릭터: 당연하지! 사과는 내가 제일 좋아하는 거란다.">{{ old('example_dialogue', $character?->example_dialogue) }}</textarea>
                @error('example_dialogue')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-section-title">소설 배경 설정 (선택)</div>

            <div class="form-group">
                <label for="world_setting">세계관 / 배경 <span class="form-optional">(선택, 2000자 — 이 캐릭터가 속한 소설의 세계 설정)</span></label>
                <textarea id="world_setting" name="world_setting" maxlength="2000" rows="5" class="form-textarea" placeholder="예: 중세풍 행상인들이 오가는 대륙. 정령과 신이 잊혀가는 시대. 풍요의 신 호로는 수백 년간 마을의 밀 농사를 지켜왔다...">{{ old('world_setting', $character?->world_setting) }}</textarea>
                @error('world_setting')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-group">
                <label for="relationships">작품 속 인물 관계 <span class="form-optional">(선택, 2000자 — 세계관 속 주요 인물들과의 관계. 유저와의 관계가 아닙니다)</span></label>
                <textarea id="relationships" name="relationships" maxlength="2000" rows="4" class="form-textarea" placeholder="예:&#10;로렌스 — 여행을 함께하는 행상인 동료. '멍청한 양치기'라고 놀리며 부른다.&#10;노라 — 경쟁 관계의 양치기. 서먹하지만 인정하고 있다.">{{ old('relationships', $character?->relationships) }}</textarea>
                @error('relationships')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="form-group">
                <label for="user_persona">대화 상대(유저) 페르소나 <span class="form-optional">(선택, 1000자 — 채팅하는 유저가 어떤 인물로 등장할지)</span></label>
                <textarea id="user_persona" name="user_persona" maxlength="1000" rows="3" class="form-textarea" placeholder="예: 유저는 이 마을에 처음 온 젊은 행상인으로, 캐릭터와는 오늘 처음 만난 사이다.">{{ old('user_persona', $character?->user_persona) }}</textarea>
                @error('user_persona')<p class="form-error">{{ $message }}</p>@enderror
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
.form-section-title {
    margin: 28px 0 16px;
    padding-top: 18px;
    border-top: 1px solid var(--border-color);
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--text-primary);
}
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
.form-ai-box {
    margin-bottom: 24px;
    padding: 18px;
    background: linear-gradient(135deg, rgba(56, 189, 248, 0.08), rgba(139, 92, 246, 0.08));
    border: 1px solid rgba(139, 92, 246, 0.35);
    border-radius: 14px;
}
.form-ai-box > label { display: block; font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
.form-ai-box .form-hint { margin: 0 0 12px; }
.form-btn-analyze {
    margin-top: 12px;
    padding: 10px 18px;
    background: linear-gradient(135deg, #38bdf8, #6366f1);
    border: none;
    border-radius: 10px;
    color: #fff;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
}
.form-btn-analyze:hover { opacity: 0.94; }
.form-btn-analyze:disabled { opacity: 0.6; cursor: not-allowed; }
.form-analyze-status { margin-left: 12px; font-size: 0.82rem; color: var(--text-secondary); }
.form-flash { animation: form-flash 1s ease; }
@keyframes form-flash {
    0% { background: rgba(56, 189, 248, 0.25); }
    100% { background: rgba(255, 255, 255, 0.05); }
}
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

// 소설 정보 분석 → 페르소나 폼 자동 채우기
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('btn_analyze_novel');
    if (!btn) return;
    var url = btn.getAttribute('data-url');
    var csrf = btn.getAttribute('data-csrf');
    var source = document.getElementById('novel_source');
    var status = document.getElementById('analyze_status');

    // persona 키 → 폼 요소 id
    var keyToId = {
        name: 'character_name', short_intro: 'short_intro', character_detail: 'character_detail',
        personality: 'personality', appearance: 'appearance', likes: 'likes', dislikes: 'dislikes',
        user_alias: 'user_alias', speech_style: 'speech_style', example_dialogue: 'example_dialogue',
        world_setting: 'world_setting', relationships: 'relationships'
    };

    function fillField(id, value) {
        var el = document.getElementById(id);
        if (!el || !value) return;
        el.value = value;
        el.classList.remove('form-flash');
        void el.offsetWidth; // 리플로우로 애니메이션 재시작
        el.classList.add('form-flash');
    }

    function setSelect(id, value) {
        var el = document.getElementById(id);
        if (!el || !value) return;
        var ok = Array.prototype.some.call(el.options, function(o) { return o.value === value; });
        if (ok) el.value = value;
    }

    btn.addEventListener('click', function() {
        var text = (source.value || '').trim();
        if (text.length < 10) {
            status.textContent = '작품 정보를 10자 이상 입력해 주세요.';
            return;
        }
        btn.disabled = true;
        btn.textContent = '분석 중...';
        status.textContent = 'AI가 작품을 분석하고 있어요…';

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ source: text })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var p = (data && data.persona) || {};
            var count = 0;
            Object.keys(keyToId).forEach(function(key) {
                if (p[key]) { fillField(keyToId[key], p[key]); count++; }
            });
            if (p.genre) setSelect('genre', p.genre);
            if (p.target) setSelect('target', p.target);
            status.textContent = count > 0
                ? count + '개 항목을 채웠어요. 내용을 확인하고 다듬어 주세요.'
                : '분석 결과가 비어 있어요. 정보를 더 자세히 입력해 보세요.';
        })
        .catch(function() {
            status.textContent = '분석에 실패했어요. 잠시 후 다시 시도해 주세요.';
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = '✨ AI로 분석해 채우기';
        });
    });
});
</script>
@endpush
