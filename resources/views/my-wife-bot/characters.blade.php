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
            <h1 class="my-wife-bot-header-title">캐릭터 모아보기</h1>
        </header>

        <p class="my-wife-bot-lead">대화하고 싶은 캐릭터를 골라 보세요.</p>

        <section class="characters-grid characters-grid--single">
            @foreach($characters as $char)
                <article class="character-card {{ $char['accent'] ?? 'accent-violet' }}">
                    <div class="character-image-wrap">
                        <img src="{{ asset($char['image'] ?? '') }}" alt="{{ $char['name'] }}" class="character-image" />
                    </div>
                    <h2 class="character-name">{{ $char['name'] }}</h2>
                    <p class="character-description">{{ $char['description'] }}</p>
                    <a href="{{ route('my-wife-bot.characters') }}?c={{ $char['id'] }}" class="character-button">
                        대화하기
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                    </a>
                </article>
            @endforeach
        </section>
    </div>
@endsection

@push('styles')
<style>
/* 캐릭터 모아보기 (MyWifeBot) */
.my-wife-bot-characters-page .my-wife-bot-characters-wrapper { margin-top: 0; }
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
.character-description {
    color: var(--text-secondary);
    font-size: 0.9rem;
    line-height: 1.6;
    margin-bottom: 20px;
    flex: 1;
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
