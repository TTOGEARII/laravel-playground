@props(['title', 'updated' => null])

{{-- 약관/정책/라이센스 페이지 공통 골격(뒤로가기·제목·개정일·본문 래퍼). 본문은 slot 으로. --}}
<div class="legal">
    <a href="{{ url('/') }}" class="legal-back">← 홈으로</a>
    <h1 class="legal-title">{{ $title }}</h1>
    @if ($updated)
        <p class="legal-updated">최종 개정일: {{ $updated }}</p>
    @endif

    <div class="legal-body">
        {{ $slot }}
    </div>
</div>
