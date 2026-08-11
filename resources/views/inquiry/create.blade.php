@extends('layouts.app')

@section('title', '문의하기 | Kanenashi Togeari')

@section('content')
    <section class="shell">
        <div class="phero">
            <a class="back" href="{{ url('/') }}">← 홈으로</a>
            <span class="tag">✉️ CONTACT</span>
            <h1>문의하기</h1>
            <p>일반 문의 · 버그 제보 · 기능 요청을 남겨 주세요. 답변이 필요하면 연락처를 함께 적어 주시면 됩니다.</p>
        </div>
    </section>

    <section class="shell" style="padding-bottom:var(--s6)">
        @if (session('inquiry_success'))
            <div class="inquiry-notice" role="status" style="max-width:720px;margin:0 auto var(--s3)">
                {{ session('inquiry_success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('inquiry.store') }}" novalidate
              class="card" style="max-width:720px;margin:0 auto;gap:var(--s3);padding:var(--s5) var(--s4)">
            @csrf

            {{-- 문의 유형: 탭 UI + 실제 제출은 hidden input(카테고리 enum) --}}
            <div class="field">
                <label>문의 유형</label>
                <div class="tabs" id="inq-cat-tabs">
                    @foreach ($categories as $category)
                        <button type="button" class="tab" data-cat="{{ $category->value }}"
                            @class(['on' => old('category', 'general') === $category->value])>
                            {{ $category->label() }}
                        </button>
                    @endforeach
                </div>
                <input type="hidden" name="category" id="inq-category" value="{{ old('category', 'general') }}">
                @error('category')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid-2" style="gap:var(--s3)">
                <div class="field">
                    <label for="name">이름 / 닉네임</label>
                    <input class="input" type="text" id="name" name="name" maxlength="50"
                        value="{{ old('name', auth()->user()?->name) }}" placeholder="어떻게 불러드릴까요?" required>
                    @error('name')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="field">
                    <label for="contact">연락처 <span style="color:var(--tx3)">(선택)</span></label>
                    <input class="input" type="text" id="contact" name="contact" maxlength="120"
                        value="{{ old('contact') }}" placeholder="이메일 또는 디스코드">
                    @error('contact')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="field">
                <label for="subject">제목</label>
                <input class="input" type="text" id="subject" name="subject" maxlength="120"
                    value="{{ old('subject') }}" placeholder="한 줄로 요약해 주세요" required>
                @error('subject')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="message">내용</label>
                <textarea class="input" id="message" name="message" rows="7" maxlength="2000"
                    placeholder="어떤 상황에서 무엇이 필요했는지 적어 주시면 큰 도움이 됩니다. (최소 10자)" required>{{ old('message') }}</textarea>
                @error('message')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="btn btn-fill btn-block" type="submit">문의 보내기</button>
            <span style="font-size:12px;color:var(--tx3);text-align:center">남겨주신 내용은 문의 처리 목적으로만 사용됩니다.</span>
        </form>
    </section>

    @push('styles')
        <style>
            .form-error { margin-top: 6px; font-size: 12px; color: var(--ds-negative); }
            .inquiry-notice {
                padding: 12px 16px; border-radius: var(--r-m);
                background: var(--chip-bg); border: 1px solid var(--accent2);
                color: var(--hd2); font-size: 14px;
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            (function () {
                var tabs = document.getElementById('inq-cat-tabs');
                var input = document.getElementById('inq-category');
                if (!tabs || !input) return;
                tabs.addEventListener('click', function (e) {
                    var btn = e.target.closest('.tab');
                    if (!btn) return;
                    tabs.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('on'); });
                    btn.classList.add('on');
                    input.value = btn.dataset.cat || 'general';
                });
            })();
        </script>
    @endpush
@endsection
