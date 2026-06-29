@php($redeemUrl = $code->game?->redeemUrlFor($code->code))
<article class="sgi-code-card sgi-status-{{ $code->status->value }}">
    <div class="sgi-code-row">
        <code class="sgi-code-text">{{ $code->code }}</code>
        <button type="button" class="sgi-copy" data-code="{{ $code->code }}">복사</button>
    </div>

    <div class="sgi-code-meta">
        <span class="sgi-badge sgi-badge-status">{{ $code->status->label() }}</span>
        @if ($code->region->value !== 'global')
            <span class="sgi-badge">{{ $code->region->label() }}</span>
        @endif
        @if ($code->rewards)
            <span class="sgi-rewards">🎁 {{ $code->rewards }}</span>
        @endif
    </div>

    <div class="sgi-code-actions">
        @if ($redeemUrl)
            <a href="{{ $redeemUrl }}" target="_blank" rel="noopener" class="sgi-redeem-btn">교환하기 ↗</a>
        @elseif ($code->game?->redeem_note)
            <span class="sgi-redeem-note">{{ $code->game->redeem_note }}</span>
        @endif
        <span class="sgi-source">출처: {{ $code->source }}</span>
    </div>
</article>
