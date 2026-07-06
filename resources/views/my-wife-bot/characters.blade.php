@extends('layouts.app')

@section('title', '캐릭터 모아보기 - MyWifeBot')

@section('body-class', 'my-wife-bot-characters-page')

@section('content')
    <div class="my-wife-bot-characters-wrapper">
        <header class="my-wife-bot-header-bar">
            <a href="{{ url('/') }}" class="back-button">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                돌아가기
            </a>
            <h1 class="my-wife-bot-header-title">{{ $title ?? '캐릭터 모아보기' }}</h1>
            <div class="my-wife-bot-header-actions">
                @auth
                    <a href="{{ route('my-wife-bot.my-characters') }}" class="my-characters-button">
                        내 챗봇 관리
                    </a>
                    <a href="{{ route('my-wife-bot.characters.create') }}" class="add-character-button">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        챗봇 추가하기
                    </a>
                @else
                    <a href="{{ route('login') }}" class="add-character-button">
                        로그인하고 챗봇 만들기
                    </a>
                @endauth
            </div>
        </header>

        @if(session('message'))
            <p class="characters-message">{{ session('message') }}</p>
        @endif

        <p class="my-wife-bot-lead">{{ $lead ?? '대화하고 싶은 캐릭터를 골라 보세요.' }}</p>

        @if($characters->isEmpty())
            <p class="characters-empty">등록된 캐릭터가 없습니다. @auth<a href="{{ route('my-wife-bot.characters.create') }}">챗봇 추가하기</a>에서 첫 캐릭터를 만들어 보세요.@else<a href="{{ route('login') }}">로그인</a> 후 첫 캐릭터를 만들어 보세요.@endauth</p>
        @else
        <section class="characters-grid {{ $characters->count() === 1 ? 'characters-grid--single' : '' }}">
            @foreach($characters as $char)
                <article class="character-card {{ $char['accent'] ?? 'accent-violet' }}">
                    <div class="character-image-wrap">
                        @if(!empty($char['image']))
                            <img src="{{ $char['image'] }}" alt="{{ $char['name'] }}" class="character-image" />
                        @else
                            <div class="character-image-placeholder">🖼</div>
                        @endif
                    </div>
                    <h2 class="character-name">{{ $char['name'] }}</h2>
                    <p class="character-description">{{ $char['description'] }}</p>
                    @if(!empty($char['has_more']))
                        <button type="button" class="character-more-button"
                                data-name="{{ $char['name'] }}"
                                data-image="{{ $char['image'] }}"
                                data-intro="{{ $char['short_intro'] }}"
                                data-detail="{{ $char['detail_full'] }}">더보기</button>
                    @endif
                    <div class="character-actions">
                        <a href="{{ route('my-wife-bot.chat', $char['id']) }}" class="character-button">
                            대화하기
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </a>
                        @if(!empty($showActions))
                            <a href="{{ route('my-wife-bot.characters.edit', $char['id']) }}" class="character-link character-link--edit">수정</a>
                            <form action="{{ route('my-wife-bot.characters.destroy', $char['id']) }}" method="POST" class="character-delete-form" onsubmit="return confirm('이 캐릭터를 삭제할까요?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="character-link character-link--delete">삭제</button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
        @endif
    </div>

    {{-- 캐릭터 상세 모달 — 카드에서 잘린 설명 전문과 일러스트를 보여준다 --}}
    <div class="character-modal" id="character-modal" hidden>
        <div class="character-modal-backdrop" data-modal-close></div>
        <div class="character-modal-panel" role="dialog" aria-modal="true" aria-labelledby="character-modal-name">
            <button type="button" class="character-modal-close" data-modal-close aria-label="닫기">&times;</button>
            <div class="character-modal-body">
                <div class="character-modal-illust">
                    <img id="character-modal-image" src="" alt="" hidden />
                    <div id="character-modal-placeholder" class="character-image-placeholder" hidden>🖼</div>
                </div>
                <div class="character-modal-text">
                    <h2 id="character-modal-name"></h2>
                    <p id="character-modal-intro" class="character-modal-intro"></p>
                    <p id="character-modal-detail" class="character-modal-detail"></p>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
/* 캐릭터 모아보기 (MyWifeBot) */
.my-wife-bot-characters-page .my-wife-bot-characters-wrapper { margin-top: 0; }
.my-wife-bot-header-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    padding: 12px 0 24px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 32px;
}
.add-character-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    background: linear-gradient(135deg, var(--accent-2), rgba(139, 92, 246, 0.85));
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: 0.9375rem;
    font-weight: 500;
    text-decoration: none;
    transition: opacity 0.2s;
}
.add-character-button:hover { opacity: 0.95; }
.my-wife-bot-header-actions {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}
.my-characters-button {
    display: inline-flex;
    align-items: center;
    padding: 10px 18px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    color: var(--text-secondary);
    font-size: 0.9375rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
}
.my-characters-button:hover {
    color: var(--accent-2);
    border-color: rgba(139, 92, 246, 0.4);
    background: rgba(139, 92, 246, 0.1);
}
.characters-message {
    padding: 12px 16px;
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(34, 197, 94, 0.3);
    border-radius: 10px;
    color: #86efac;
    font-size: 0.875rem;
    margin-bottom: 20px;
}
.characters-empty {
    color: var(--text-secondary);
    font-size: 1rem;
    margin-bottom: 24px;
}
.characters-empty a { color: var(--accent-2); text-decoration: none; }
.characters-empty a:hover { text-decoration: underline; }
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
    transition: all 0.3s ease;
}
.my-wife-bot-header-bar .back-button:hover {
    background: rgba(255, 255, 255, 0.08);
    color: var(--text-primary);
    transform: translateX(-2px);
}
.my-wife-bot-header-bar .back-button svg { width: 16px; height: 16px; }
.my-wife-bot-header-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary);
}
.my-wife-bot-lead {
    color: var(--text-secondary);
    font-size: 1rem;
    margin-bottom: 32px;
}
.characters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 24px;
}
.characters-grid--single {
    grid-template-columns: 1fr;
    max-width: 420px;
    margin: 0 auto;
}
.character-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 28px;
    transition: all 0.35s ease;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}
.character-card:hover {
    transform: translateY(-6px);
    border-color: rgba(255, 255, 255, 0.12);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.25);
}
.character-card.accent-indigo { --char-accent: var(--accent-1); }
.character-card.accent-violet { --char-accent: var(--accent-2); }
.character-card.accent-pink { --char-accent: var(--accent-3); }
.character-card.accent-teal { --char-accent: var(--accent-4); }
.character-image-wrap {
    width: 100%;
    aspect-ratio: 1;
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 20px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border-color);
}
.character-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.character-name {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text-primary);
}
.character-image-placeholder {
    width: 100%;
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    background: rgba(255,255,255,0.05);
    border-radius: 16px;
}
.character-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 8px;
}
.character-delete-form { display: inline; margin: 0; }
.character-link {
    padding: 6px 12px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
    text-decoration: none;
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
}
.character-link:hover { color: var(--text-primary); }
.character-link--edit { color: var(--accent-2); }
.character-link--delete { color: #f87171; }
.character-description {
    color: var(--text-secondary);
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 8px;
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.character-more-button {
    background: none;
    border: none;
    color: var(--accent-2);
    font-size: 0.8125rem;
    font-family: inherit;
    cursor: pointer;
    padding: 4px 8px;
    margin-bottom: 12px;
}
.character-more-button:hover { text-decoration: underline; }

/* 캐릭터 상세 모달 */
.character-modal { position: fixed; inset: 0; z-index: 100; }
.character-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(4px);
}
.character-modal-panel {
    position: relative;
    max-width: 720px;
    width: calc(100% - 32px);
    max-height: calc(100vh - 80px);
    margin: 40px auto;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 28px;
    overflow-y: auto;
}
.character-modal-close {
    position: absolute;
    top: 14px;
    right: 16px;
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 1.75rem;
    line-height: 1;
    cursor: pointer;
}
.character-modal-close:hover { color: var(--text-primary); }
.character-modal-body { display: flex; gap: 24px; }
.character-modal-illust { flex: 0 0 260px; }
.character-modal-illust img {
    width: 100%;
    border-radius: 16px;
    border: 1px solid var(--border-color);
    display: block;
}
.character-modal-text { flex: 1; min-width: 0; }
.character-modal-text h2 { font-size: 1.375rem; margin-bottom: 8px; color: var(--text-primary); }
.character-modal-intro { color: var(--text-primary); font-size: 0.95rem; margin-bottom: 14px; font-weight: 500; }
.character-modal-detail { color: var(--text-secondary); font-size: 0.9rem; line-height: 1.7; white-space: pre-wrap; }
@media (max-width: 640px) {
    .character-modal-body { flex-direction: column; }
    .character-modal-illust { flex: none; max-width: 320px; margin: 0 auto; }
}
.character-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: linear-gradient(135deg, var(--char-accent, var(--accent-2)), color-mix(in srgb, var(--char-accent, var(--accent-2)) 75%, black));
    color: white;
    text-decoration: none;
    border-radius: 12px;
    font-weight: 500;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}
.character-button:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 20px rgba(139, 92, 246, 0.35);
}
</style>
@endpush

@push('scripts')
<script>
// 캐릭터 상세 모달 — 더보기 클릭 시 전체 설명·일러스트 표시 (백드롭/ESC/× 로 닫기)
(function () {
    var modal = document.getElementById('character-modal');
    if (!modal) return;

    var img = document.getElementById('character-modal-image');
    var placeholder = document.getElementById('character-modal-placeholder');

    function openModal(btn) {
        document.getElementById('character-modal-name').textContent = btn.dataset.name || '';
        document.getElementById('character-modal-intro').textContent = btn.dataset.intro || '';
        document.getElementById('character-modal-detail').textContent = btn.dataset.detail || '';
        if (btn.dataset.image) {
            img.src = btn.dataset.image;
            img.alt = btn.dataset.name || '';
            img.hidden = false;
            placeholder.hidden = true;
        } else {
            img.hidden = true;
            placeholder.hidden = false;
        }
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.character-more-button').forEach(function (btn) {
        btn.addEventListener('click', function () { openModal(btn); });
    });
    modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
</script>
@endpush
