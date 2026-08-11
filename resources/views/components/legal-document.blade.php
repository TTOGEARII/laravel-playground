@props(['title', 'eyebrow' => null, 'updated' => null])

{{-- 약관/정책/라이센스 공통 골격. 히어로(뒤로가기·배지·제목·리드) + .doc 본문(개정일·섹션들).
     리드 문단은 <x-slot:lead> 로, 본문 섹션(<section><h2>…</h2>…)은 기본 슬롯으로 넘긴다. --}}
<section class="shell">
    <div class="phero">
        <a class="back" href="{{ url('/') }}">← 홈으로</a>
        @if ($eyebrow)
            <span class="tag">{{ $eyebrow }}</span>
        @endif
        <h1>{{ $title }}</h1>
        {{ $lead ?? '' }}
    </div>
</section>

<section class="shell" style="padding-bottom:var(--s6)">
    <div class="doc">
        @if ($updated)
            <span class="meta">최종 개정일 · {{ $updated }}</span>
        @endif
        {{ $slot }}
    </div>
</section>
