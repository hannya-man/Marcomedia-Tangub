{{--
    Stock alert banner for the layout: every user sees it on every page.
    Reads the batch engine's active alerts (stock_alerts), which update on every stock change.
--}}
@php
    $bannerAlerts = \App\Models\StockAlert::with('material')->active()->mostUrgentFirst()->get();
    $bannerFirst = $bannerAlerts->first();
    // A material with no type yet behaves as discrete, and both pages list it under "Not set up yet".
    $bannerUrl = $bannerFirst && $bannerFirst->material && $bannerFirst->material->inventory_type === 'continuous'
        ? route('stock.continuous')
        : route('stock.discrete');
@endphp
@if($bannerAlerts->isNotEmpty() && ! request()->routeIs('stock.continuous', 'stock.discrete'))
    <a href="{{ $bannerUrl }}"
       class="mx-8 mt-4 flex items-center gap-3 rounded-lg bg-amber-100 dark:bg-amber-900/30 border border-amber-400 text-amber-700 dark:text-amber-400 px-4 py-3 text-sm">
        <i data-lucide="alert-triangle" class="w-4 h-4 flex-shrink-0"></i>
        <span class="flex-1 min-w-0 truncate">
            <strong class="font-semibold">{{ $bannerAlerts->count() }} stock {{ $bannerAlerts->count() === 1 ? 'alert' : 'alerts' }}.</strong>
            {{ $bannerAlerts->take(2)->pluck('message')->implode(' ') }}
        </span>
        <span class="text-xs font-medium flex-shrink-0">View</span>
    </a>
@endif
