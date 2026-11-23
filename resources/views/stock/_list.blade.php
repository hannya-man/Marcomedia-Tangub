{{-- The materials on the Continuous or Discrete page: a summary bar (how many are in stock, running
     low, out) over the list of rows. Needs $cards and $kind. --}}
@if($cards->isNotEmpty())
@php
    $counts = $cards->countBy('state');
    $total = $cards->count();
    $parts = [
        ['ok', 'In stock', '#146c84'],
        ['low', 'Running low', '#d97706'],
        ['out', 'Out of stock', '#ef4444'],
    ];
@endphp
<div class="bg-white rounded-xl border border-slate-200 p-4 mb-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm font-medium text-ink">{{ $total }} {{ $total === 1 ? 'material' : 'materials' }}</p>
        @if(($counts['low'] ?? 0) + ($counts['out'] ?? 0))
            <p class="text-xs text-slate-500">Buy more of the ones at the top of the list first.</p>
        @endif
    </div>
    <div class="mt-3 flex rounded-full overflow-hidden bg-slate-100" style="height:10px;" role="img"
         aria-label="{{ collect($parts)->map(fn ($p) => ($counts[$p[0]] ?? 0) . ' ' . strtolower($p[1]))->implode(', ') }}">
        @foreach($parts as [$state, $label, $color])
            @if($counts[$state] ?? 0)
                <div title="{{ $counts[$state] }} {{ strtolower($label) }}" style="width:{{ $counts[$state] / $total * 100 }}%; background:{{ $color }};"></div>
            @endif
        @endforeach
    </div>
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-3">
        @foreach($parts as [$state, $label, $color])
            <div class="flex items-center gap-2 min-w-0">
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $color }};"></span>
                <p class="text-xs text-slate-500 truncate">{{ $label }}</p>
                <p class="text-sm font-semibold text-ink ml-auto sm:ml-0">{{ $counts[$state] ?? 0 }}</p>
            </div>
        @endforeach
    </div>
</div>
@endif

@include('stock._search')

@if($cards->isNotEmpty())
<div class="bg-white rounded-xl border border-slate-200">
    <div class="hidden lg:grid lg:grid-cols-[minmax(0,2fr)_7.5rem_minmax(0,1.4fr)_minmax(0,1.4fr)_21rem] gap-x-4 px-4 py-2 border-b border-slate-200 text-xs font-medium text-slate-500">
        <span>Material</span><span>Status</span><span>In stock <span class="font-normal text-slate-400">(line = buy more)</span></span><span>{{ $kind === 'continuous' ? 'Pack in use' : 'Pack in use / ready' }}</span><span></span>
    </div>
    @foreach($cards as $card)
        @include('stock._row', ['card' => $card, 'kind' => $kind])
    @endforeach
</div>
@endif
