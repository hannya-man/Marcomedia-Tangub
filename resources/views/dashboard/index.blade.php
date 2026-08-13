@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
        <div class="flex items-center justify-between mb-2">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">Today's Sales</p>
            <i data-lucide="banknote" class="w-4 h-4 text-brand-500"></i>
        </div>
        <p class="text-2xl font-semibold text-ink dark:text-white">₱{{ number_format($todaySales, 2) }}</p>
        <p class="text-xs text-slate-400 mt-1">{{ $todayTransactions }} transaction{{ $todayTransactions === 1 ? '' : 's' }} today</p>
    </div>
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
        <div class="flex items-center justify-between mb-2">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">This Month</p>
            <i data-lucide="calendar-clock" class="w-4 h-4 text-brand-500"></i>
        </div>
        <p class="text-2xl font-semibold text-ink dark:text-white">₱{{ number_format($monthSales, 2) }}</p>
        <p class="text-xs text-slate-400 mt-1">{{ now()->format('F Y') }}</p>
    </div>
    <a href="{{ route('inventory.index') }}" class="block bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 hover:border-brand-400 transition-colors">
        <div class="flex items-center justify-between mb-2">
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">Stock Items</p>
            <i data-lucide="package" class="w-4 h-4 text-brand-500"></i>
        </div>
        <p class="text-xs {{ $lowStockCount + $outOfStockCount > 0 ? 'text-amber-600' : 'text-slate-400' }} mt-1">
            {{ $lowStockCount }} low · {{ $outOfStockCount }} out
        </p>
    </a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <div class="flex items-center justify-between mb-4">
                <p class="font-medium text-ink dark:text-white flex items-center gap-2">Sales Overview <a href="{{ route('sales.index') }}" class="text-xs text-brand-600 font-normal hover:underline">View all →</a></p>
                <div class="flex gap-1 bg-slate-100 dark:bg-slate-700 rounded-lg p-1" x-data="{ period: 'weekly' }" x-init="$watch('period', p => window.setChartPeriod(p))">
                    <button @click="period = 'weekly'" :class="period === 'weekly' ? 'bg-white dark:bg-slate-600 shadow-sm font-medium dark:text-white' : 'text-slate-500 dark:text-slate-300'" class="text-xs px-3 py-1 rounded-md">Weekly</button>
                    <button @click="period = 'monthly'" :class="period === 'monthly' ? 'bg-white dark:bg-slate-600 shadow-sm font-medium dark:text-white' : 'text-slate-500 dark:text-slate-300'" class="text-xs px-3 py-1 rounded-md">Monthly</button>
                    <button @click="period = 'yearly'" :class="period === 'yearly' ? 'bg-white dark:bg-slate-600 shadow-sm font-medium dark:text-white' : 'text-slate-500 dark:text-slate-300'" class="text-xs px-3 py-1 rounded-md">Yearly</button>
                </div>
            </div>
            <canvas id="salesChart" height="90"></canvas>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <div class="flex items-center justify-between mb-4">
                <p class="font-medium text-ink dark:text-white">Recent Sales</p>
                <a href="{{ route('sales.index') }}" class="text-xs text-brand-600 hover:underline">View all →</a>
            </div>
            <div class="overflow-x-auto">
<table class="w-full text-sm">
                <thead><tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                    <th class="pb-2">Invoice</th><th class="pb-2">Items</th><th class="pb-2">Total</th><th class="pb-2">Status</th>
                </tr></thead>
                <tbody>
                    @forelse($recentSales as $s)
                    <tr class="border-b border-slate-50 dark:border-slate-700">
                        <td class="py-2 text-ink dark:text-white">{{ $s->invoice_number }}</td>
                        <td>{{ $s->items->count() }}</td>
                        <td>₱{{ number_format($s->total_amount, 2) }}</td>
                        <td><span class="px-2 py-0.5 rounded-full text-xs {{ $s->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ ucfirst($s->status) }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-slate-400">No sales recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
</div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-ink rounded-xl p-5 text-center text-white">
            <p id="liveClock" class="text-3xl font-semibold tracking-wider">00:00:00</p>
            <p class="text-xs text-slate-400 uppercase tracking-wider mt-2">{{ now()->format('l, F j Y') }}</p>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <div class="flex items-center justify-between mb-4">
                <p class="font-medium text-ink dark:text-white">Recent Stock Activity</p>
                <a href="{{ route('inventory.index') }}" class="text-xs text-brand-600 hover:underline">View all →</a>
            </div>
            <div class="space-y-3">
                @forelse($recentMovements as $m)
                    <div class="flex items-center gap-3 text-sm">
                        <div class="w-8 h-8 rounded-lg bg-brand-50 dark:bg-brand-600/20 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="{{ $m->quantity >= 0 ? 'package-check' : 'package-minus' }}" class="w-4 h-4 text-brand-600"></i>
                        </div>
                        <div>
                            <p class="text-ink dark:text-white">
                                {{ $m->variant->product->name ?? 'Product' }} ({{ $m->variant->variant_name ?? '-' }}):
                                {{ $m->quantity >= 0 ? '+' : '' }}{{ $m->quantity }}
                            </p>
                            <p class="text-xs text-slate-400">{{ $m->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">No stock activity yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
function tickClock(){ document.getElementById('liveClock').textContent = new Date().toLocaleTimeString(); }
setInterval(tickClock, 1000); tickClock();

const chartSets = @json($chartSets);
const salesChart = new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: { labels: chartSets.weekly.labels,
        datasets: [{ data: chartSets.weekly.data, backgroundColor: '#6366f1', borderRadius: 6 }] },
    options: {
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (ctx) => '₱' + ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 }) } }
        },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { callback: (value) => '₱' + value.toLocaleString('en-US') } },
            x: { grid: { display: false } }
        }
    }
});

window.setChartPeriod = function (period) {
    const set = chartSets[period];
    if (!set) return;
    salesChart.data.labels = set.labels;
    salesChart.data.datasets[0].data = set.data;
    salesChart.update();
};
</script>
@endsection
