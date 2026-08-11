@props(['game', 'index' => 0, 'tone' => 'pink', 'featured' => false])

@php
    // 랭킹 대상(내부 게임)이 아니면 '외부 게임', 준비중이면 비활성 처리.
    $isSoon = ($game['status'] ?? '') === 'coming-soon';
    $href = ! $isSoon && isset($game['route']) ? route($game['route']) : null;
    $no = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
@endphp

<a @class(['card', $tone, 'gcard', 'gcard-lg' => $featured, 'is-soon' => $isSoon])
    @if ($href) href="{{ $href }}" @endif>
    <span class="card-no">{{ $no }}</span>
    <div class="gcard-top">
        <span class="card-ico" @if ($featured) style="width:60px;height:60px;font-size:28px" @endif>{{ $game['icon'] }}</span>
        @if ($featured)
            <span class="chip gold">★ 이번 주 인기</span>
        @elseif ($isSoon)
            <span class="chip">준비중</span>
        @else
            <span class="chip cyan"><span class="dot-live"></span> 플레이 가능</span>
        @endif
    </div>
    <div class="card-body">
        <h3>{{ $game['name'] }}</h3>
        <p>{{ $game['description'] }}</p>
        <div class="chips">
            @foreach ($game['tags'] as $tag)
                <span class="chip">{{ $tag }}</span>
            @endforeach
        </div>
    </div>
    <div class="gcard-foot">
        <span class="gmeta">{{ ($game['external'] ?? false) ? '외부 게임' : '랭킹 지원' }}</span>
        <span class="enter">{{ $isSoon ? '준비중' : '게임 시작 →' }}</span>
    </div>
</a>
