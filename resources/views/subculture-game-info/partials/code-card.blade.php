@php($redeemUrl = $code->game?->redeemUrlFor($code->code))
<article class="code-card status-{{ $code->status->value }} {{ $code->is_verified ? 'is-verified' : '' }}"
         data-code-id="{{ $code->id }}">
    <div class="code-top">
        <code class="code-val">{{ $code->code }}</code>
        <button type="button" class="copy js-copy" data-code="{{ $code->code }}">복사</button>
    </div>

    <div class="chips">
        <span class="chip cyan">{{ $code->status->label() }}</span>
        @if ($code->is_verified)
            <span class="chip" title="여러 출처에서 확인됨">✓ 교차검증 {{ $code->corroboration_count }}</span>
        @endif
        @if ($code->expires_at)
            @php($d = $code->days_left)
            <span class="chip gold">⏰ {{ $code->expires_at->format('Y.m.d') }}{{ $d !== null && $d >= 0 ? " (D-{$d})" : '' }}</span>
        @endif
        @if ($code->region->value !== 'global')
            <span class="chip">{{ $code->region->label() }}</span>
        @endif
    </div>

    @if ($code->rewards)
        <p class="reward">🎁 {{ \Illuminate\Support\Str::limit($code->rewards, 90) }}</p>
    @endif

    <div class="code-foot row" style="justify-content:space-between;margin-top:auto">
        <div class="row" style="gap:10px">
            @if ($redeemUrl)
                <a class="enter" href="{{ $redeemUrl }}" target="_blank" rel="noopener">교환하기 ↗</a>
            @elseif ($code->game?->redeem_note)
                <span class="src">{{ $code->game->redeem_note }}</span>
            @endif
            <button type="button" class="code-done js-redeemed" data-code-id="{{ $code->id }}" aria-pressed="false">
                <span class="code-done-chk">✓</span><span class="code-done-lbl">교환완료</span>
            </button>
        </div>
        <span class="src">출처: {{ implode(', ', $code->seen_sources ?? [$code->source]) }}</span>
    </div>
</article>
