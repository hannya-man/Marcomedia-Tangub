{{--
    Stock alerts panel. Replaces the logbook check.
    Add to resources/views/dashboard/index.blade.php, right after @section('content'):
        @include('partials.stock-alerts')
    Works on any page. Pass $stockAlerts yourself, or it loads the active ones.
--}}
@php($stockAlerts = $stockAlerts ?? \App\Models\StockAlert::with(['material', 'acknowledger'])->active()->mostUrgentFirst()->get())
@php($alertStyles = [
    'out_of_stock' => ['label' => 'Out of stock', 'icon' => 'package-x', 'badge' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'],
    'low_stock' => ['label' => 'Low stock', 'icon' => 'package-minus', 'badge' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
    'transfer_needed' => ['label' => 'Move from warehouse', 'icon' => 'truck', 'badge' => 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300'],
])

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 mb-6">
    <div class="flex items-center justify-between mb-3">
        <p class="font-medium text-ink dark:text-white flex items-center gap-2">
            <i data-lucide="bell" class="w-4 h-4 text-brand-500"></i> Stock Alerts
        </p>
        <span class="text-xs {{ $stockAlerts->isEmpty() ? 'text-slate-400' : 'text-amber-600' }}">{{ $stockAlerts->count() }} active</span>
    </div>

    @forelse($stockAlerts as $alert)
        @php($style = $alertStyles[$alert->type])
        <div class="flex items-start gap-3 py-3 border-t border-slate-100 dark:border-slate-700 {{ $alert->acknowledged_at ? 'opacity-70' : '' }}">
            <i data-lucide="{{ $style['icon'] }}" class="w-4 h-4 mt-0.5 shrink-0 text-slate-500 dark:text-slate-400"></i>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $style['badge'] }}">{{ $style['label'] }}</span>
                    <span class="text-xs text-slate-400">{{ $alert->created_at->diffForHumans() }}</span>
                </div>
                <p class="text-sm text-ink dark:text-white">{{ $alert->message }}</p>
                @if($alert->acknowledged_at)
                    <p class="text-xs text-slate-400 mt-1">Seen by {{ $alert->acknowledger->name ?? 'staff' }} {{ $alert->acknowledged_at->diffForHumans() }}</p>
                @endif
            </div>
            @unless($alert->acknowledged_at)
                <form method="POST" action="{{ route('stock-alerts.acknowledge', $alert) }}">
                    @csrf
                    <button type="submit" class="text-xs text-brand-600 hover:underline whitespace-nowrap">Mark as seen</button>
                </form>
            @endunless
        </div>
    @empty
        <p class="text-sm text-slate-400">No stock alerts. Every material is above its reorder point.</p>
    @endforelse
</div>
