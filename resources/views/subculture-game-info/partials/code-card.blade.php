@php($redeemUrl = $code->game?->redeemUrlFor($code->code))
<article class="sgi-code-card sgi-status-{{ $code->status->value }} {{ $code->is_verified ? 'sgi-verified' : '' }}"
         data-code-id="{{ $code->id }}">
    <div class="sgi-code-row">
        <code class="sgi-code-text">{{ $code->code }}</code>
        <button type="button" class="sgi-copy" data-code="{{ $code->code }}">복사</button>
    </div>

    <div class="sgi-code-meta">
        <span class="sgi-badge sgi-badge-status">{{ $code->status->label() }}</span>
        @if ($code->is_verified)
            <span class="sgi-badge sgi-badge-verified" title="여러 출처에서 확인됨">✓ 교차검증 {{ $code->corroboration_count }}</span>
        @endif
        @if ($code->region->value !== 'global')
            <span class="sgi-badge">{{ $code->region->label() }}</span>
        @endif
        @if ($code->expires_at)
            @php($d = $code->days_left)
            <span class="sgi-badge sgi-badge-expiry">
                ⏰ {{ $code->expires_at->format('Y.m.d') }}{{ $d !== null && $d >= 0 ? " (D-{$d})" : '' }}
            </span>
        @endif
    </div>

    @if ($code->rewards)
        <div class="sgi-rewards">🎁 {{ \Illuminate\Support\Str::limit($code->rewards, 90) }}</div>
    @endif

    <div class="sgi-code-actions">
        @if ($redeemUrl)
            <a href="{{ $redeemUrl }}" target="_blank" rel="noopener" class="sgi-redeem-btn">교환하기 ↗</a>
        @elseif ($code->game?->redeem_note)
            <span class="sgi-redeem-note">{{ $code->game->redeem_note }}</span>
        @endif
        <button type="button" class="sgi-redeemed-toggle" data-code-id="{{ $code->id }}" aria-pressed="false">
            <span class="sgi-redeemed-check">✓</span>
            <span class="sgi-redeemed-label">교환완료</span>
        </button>
        <span class="sgi-source">출처: {{ implode(', ', $code->seen_sources ?? [$code->source]) }}</span>
    </div>
</article>
