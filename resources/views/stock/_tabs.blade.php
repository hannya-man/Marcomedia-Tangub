{{-- The four inventory types. Shown at the top of each inventory page. --}}
@php
    $stockTabs = [
        ['Products', route('inventory.index'), request()->routeIs('inventory.index')],
        ['Continuous Raw Materials', route('stock.continuous'), request()->routeIs('stock.continuous')],
        ['Discrete Materials', route('stock.discrete'), request()->routeIs('stock.discrete')],
        ['Scrapped / Rejected Output', route('stock.rejected'), request()->routeIs('stock.rejected')],
    ];
@endphp
<nav class="flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-700 mb-6" aria-label="Inventory types">
    @foreach($stockTabs as [$tabLabel, $tabUrl, $tabActive])
        <a href="{{ $tabUrl }}" @if($tabActive) aria-current="page" @endif style="margin-bottom:-1px;"
           class="px-4 py-2 text-sm whitespace-nowrap border-b-2 {{ $tabActive ? 'border-brand-600 text-brand-600 dark:text-brand-300 font-medium' : 'border-transparent text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700' }}">
            {{ $tabLabel }}
        </a>
    @endforeach
</nav>
