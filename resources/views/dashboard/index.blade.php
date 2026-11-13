@extends('layouts.app')

@section('title', 'Dashboard')

@push('scripts-head')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" integrity="sha384-DhxhYObIMeMNGyAG7iK11OHzBIKyEIeRL0ad1iFPAOwZB8iirUlTT0O/WJJUk8+o" crossorigin="anonymous"></script>
@endpush

@section('content')
@php
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
    // Up or down against the time before: ['up' | 'down' | 'new' | null, percent].
    $change = function (float $now, float $before) {
        if ($before <= 0) {
            return [$now > 0 ? 'new' : null, null];
        }
        $pct = (int) round(($now - $before) / $before * 100);

        return [$pct >= 0 ? 'up' : 'down', abs($pct)];
    };
    $badge = function (array $c, string $than) {
        return match ($c[0]) {
            'up' => '<span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 text-xs font-medium">▲ ' . $c[1] . '%</span> <span class="text-xs text-slate-400">vs ' . $than . '</span>',
            'down' => '<span class="inline-flex items-center gap-0.5 rounded-full bg-red-50 text-red-700 px-2 py-0.5 text-xs font-medium">▼ ' . $c[1] . '%</span> <span class="text-xs text-slate-400">vs ' . $than . '</span>',
            'new' => '<span class="text-xs text-slate-400">nothing ' . $than . '</span>',
            default => '<span class="text-xs text-slate-400">no sales yet</span>',
        };
    };
    ['today' => $t, 'yesterday' => $y, 'month' => $m, 'lastMonth' => $lm] = $totals;
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $cash = (float) ($payments['cash']->total ?? 0);
    $gcash = (float) ($payments['gcash']->total ?? 0);
    $received = $cash + $gcash;
    $card = 'bg-white rounded-xl border border-slate-200';
    $statusStyle = ['completed' => 'bg-emerald-50 text-emerald-700', 'processing' => 'bg-blue-50 text-blue-700', 'pending' => 'bg-amber-50 text-amber-800', 'voided' => 'bg-red-50 text-red-700'];
    $bar = fn ($v, $max) => $max > 0 ? max(3, round($v / $max * 100)) : 0;
@endphp

{{-- Welcome, what's waiting, and the things done most --}}
<div class="relative overflow-hidden rounded-2xl p-6 mb-6 text-white" style="background: linear-gradient(120deg, #0f3b47 0%, #146c84 55%, #1f8ba0 100%);">
    <div class="absolute -right-12 -top-16 w-56 h-56 rounded-full bg-white/10"></div>
    <div class="absolute right-32 -bottom-20 w-40 h-40 rounded-full bg-white/5"></div>
    <div class="relative flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-2xl font-semibold">{{ $greeting }}, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') ?: auth()->user()->name }}</p>
            <p class="text-sm text-white/80 mt-2">
                {{ $t['count'] }} {{ $t['count'] == 1 ? 'sale' : 'sales' }} completed today
                · <a href="{{ route('sales.index', ['status' => 'pending']) }}" class="underline decoration-white/40 hover:decoration-white">{{ (int) $open->pending }} waiting for payment</a>
                · <a href="{{ route('sales.index', ['status' => 'processing']) }}" class="underline decoration-white/40 hover:decoration-white">{{ (int) $open->processing }} being made</a>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('pos.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-ink hover:bg-slate-100"><i data-lucide="scan-line" class="w-4 h-4"></i> New sale</a>
            <a href="{{ route('inventory.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-white/15 px-4 py-2 text-sm font-medium hover:bg-white/25"><i data-lucide="package-plus" class="w-4 h-4"></i> Products</a>
            <a href="{{ route('reports.sales', ['period' => 'month']) }}" class="inline-flex items-center gap-2 rounded-lg bg-white/15 px-4 py-2 text-sm font-medium hover:bg-white/25"><i data-lucide="bar-chart-3" class="w-4 h-4"></i> Sales report</a>
        </div>
    </div>
</div>

{{-- The numbers, each against the time before --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    @foreach([
        ['Sales today', $peso($t['revenue']), $badge($change($t['revenue'], $y['revenue']), 'this time yesterday'), "{$t['count']} sales · {$t['items']} items", 'banknote', 'bg-brand-50 text-brand-600'],
        ['This month', $peso($m['revenue']), $badge($change($m['revenue'], $lm['revenue']), 'this point last month'), "{$m['count']} sales · {$m['items']} items", 'calendar-range', 'bg-emerald-50 text-emerald-600'],
        ['Average sale', $peso($m['average']), $badge($change($m['average'], $lm['average']), 'this point last month'), 'this month, per completed sale', 'receipt', 'bg-indigo-50 text-indigo-600'],
        ['Money received', $peso($received), '<span class="text-xs text-slate-400">this month, every payment</span>', 'Cash ' . $peso($cash) . ' · GCash ' . $peso($gcash), 'wallet', 'bg-amber-50 text-amber-600'],
    ] as [$label, $value, $compare, $note, $icon, $chip])
        <div class="{{ $card }} p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <p class="text-2xl font-semibold text-ink mt-1 truncate">{{ $value }}</p>
                </div>
                <span class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 {{ $chip }}"><i data-lucide="{{ $icon }}" class="w-5 h-5"></i></span>
            </div>
            <p class="mt-2">{!! $compare !!}</p>
            <p class="text-xs text-slate-400 mt-1">{{ $note }}</p>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    {{-- Sales over time --}}
    <div class="lg:col-span-2 {{ $card }} p-5" x-data="{ period: 'weekly' }">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <p class="font-semibold text-ink">Sales overview</p>
                <p class="text-xs text-slate-400" id="chart-total"></p>
            </div>
            <div class="flex rounded-lg bg-slate-100 p-1 text-sm">
                @foreach(['weekly' => '7 days', 'monthly' => '8 weeks', 'yearly' => '12 months'] as $key => $text)
                    <button type="button" @click="period = '{{ $key }}'; setChartPeriod('{{ $key }}')"
                            :class="period === '{{ $key }}' ? 'bg-white shadow-sm text-ink' : 'text-slate-500'" class="px-3 py-1 rounded-md">{{ $text }}</button>
                @endforeach
            </div>
        </div>
        <div class="relative h-64">
            <canvas id="salesChart" aria-label="Sales chart" role="img"></canvas>
            <p id="chart-empty" style="display:none;" class="absolute inset-0 flex items-center justify-center text-sm text-slate-400">No completed sales in this time yet.</p>
        </div>
    </div>

    {{-- This month by category --}}
    <div class="{{ $card }} p-5">
        <div class="flex items-center justify-between mb-3">
            <p class="font-semibold text-ink">By category</p>
            <span class="text-xs text-slate-400">this month</span>
        </div>
        @if($categories->isNotEmpty())
            <div class="relative h-40"><canvas id="categoryChart" aria-label="Sales by category" role="img"></canvas></div>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach($categories->take(5) as $i => $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2 min-w-0"><span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background: {{ ['#146c84', '#e0a526', '#1f8ba0', '#10b981', '#6366f1'][$i] }}"></span><span class="truncate text-slate-600">{{ $row->name }}</span></span>
                        <span class="text-slate-500 whitespace-nowrap">{{ $peso($row->revenue) }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="h-56 flex flex-col items-center justify-center text-center text-sm text-slate-400">
                <i data-lucide="pie-chart" class="w-8 h-8 mb-2 text-slate-300"></i>No completed sales this month yet.
            </div>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    {{-- What sells --}}
    <div class="{{ $card }} p-5">
        <div class="flex items-center justify-between mb-4">
            <p class="font-semibold text-ink">Top sellers</p>
            <a href="{{ route('reports.sales', ['period' => 'month']) }}" class="text-xs text-brand-600 hover:underline">This month →</a>
        </div>
        @php $max = (float) $best->max('quantity'); @endphp
        <ul class="space-y-3 text-sm">
            @forelse($best as $i => $row)
                <li>
                    <div class="flex justify-between gap-3">
                        <span class="flex items-center gap-2 min-w-0"><span class="w-5 text-xs font-semibold text-slate-400">{{ $i + 1 }}</span><span class="truncate text-slate-700">{{ $row->name }}</span></span>
                        <span class="whitespace-nowrap text-slate-500">{{ (int) $row->quantity }} sold</span>
                    </div>
                    <div class="mt-1 ml-7 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ $bar((float) $row->quantity, $max) }}%"></div></div>
                </li>
            @empty
                <li class="text-slate-400">Nothing sold this month yet.</li>
            @endforelse
        </ul>
    </div>

    {{-- Who sells --}}
    <div class="{{ $card }} p-5">
        <div class="flex items-center justify-between mb-4">
            <p class="font-semibold text-ink">Staff this month</p>
            <a href="{{ route('shifts.index') }}" class="text-xs text-brand-600 hover:underline">Shifts →</a>
        </div>
        @php $max = (float) $people->max('revenue'); @endphp
        <ul class="space-y-3 text-sm">
            @forelse($people->take(5) as $person)
                <li>
                    <a href="{{ route('reports.sales', ['period' => 'month', 'user' => $person->id]) }}" class="flex items-center gap-3 group">
                        <span class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-semibold flex-shrink-0">
                            {{ collect(explode(' ', $person->name))->map(fn ($p) => strtoupper($p[0] ?? ''))->take(2)->join('') }}
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="flex justify-between gap-2"><span class="truncate text-slate-700 group-hover:text-brand-600">{{ $person->name }}</span><span class="whitespace-nowrap font-medium text-ink">{{ $peso($person->revenue) }}</span></span>
                            <span class="block text-xs text-slate-400">{{ $person->count }} {{ $person->count == 1 ? 'sale' : 'sales' }} · {{ $person->items }} items</span>
                            <span class="block mt-1 h-1.5 rounded-full bg-slate-100"><span class="block h-1.5 rounded-full bg-gold-500" style="width: {{ $bar((float) $person->revenue, $max) }}%"></span></span>
                        </span>
                    </a>
                </li>
            @empty
                <li class="text-slate-400">No sales this month yet.</li>
            @endforelse
        </ul>
    </div>

    {{-- How customers pay, and who's working --}}
    <div class="space-y-6">
        <div class="{{ $card }} p-5">
            <p class="font-semibold text-ink mb-3">How customers paid</p>
            <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                @if($received > 0)
                    <div class="bg-brand-500" style="width: {{ round($cash / $received * 100) }}%"></div>
                    <div class="bg-sky-400" style="width: {{ round($gcash / $received * 100) }}%"></div>
                @endif
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <p><span class="flex items-center gap-1.5 text-slate-500"><span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>Cash</span><span class="font-medium text-ink">{{ $peso($cash) }}</span></p>
                <p><span class="flex items-center gap-1.5 text-slate-500"><span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span>GCash</span><span class="font-medium text-ink">{{ $peso($gcash) }}</span></p>
            </div>
            <p class="mt-2 text-xs text-slate-400">This month, down payments included</p>
        </div>
        <div class="{{ $card }} p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-ink">On shift now</p>
                <a href="{{ route('shifts.index') }}" class="text-xs text-brand-600 hover:underline">All shifts →</a>
            </div>
            <div class="space-y-2">
                @forelse($onShift as $shift)
                    <a href="{{ route('shifts.show', $shift) }}" class="flex items-center justify-between gap-3 text-sm hover:text-brand-600">
                        <span class="flex items-center gap-2 text-ink"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>{{ $shift->user->name ?? '-' }}</span>
                        <span class="text-xs text-slate-400">since {{ $shift->opened_at->isToday() ? $shift->opened_at->format('g:i A') : $shift->opened_at->format('M j, g:i A') }}</span>
                    </a>
                @empty
                    <p class="text-sm text-slate-400">Nobody has started a shift.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    {{-- The latest sales --}}
    <div class="lg:col-span-2 {{ $card }}">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
            <p class="font-semibold text-ink">Recent sales</p>
            <a href="{{ route('sales.index') }}" class="text-xs text-brand-600 hover:underline">View all →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="px-5 py-3">Invoice</th><th class="px-2">Customer</th><th class="px-2">Cashier</th><th class="px-2 text-right">Items</th><th class="px-2 text-right">Total</th><th class="px-5">Status</th>
                </tr></thead>
                <tbody>
                    @forelse($recentSales as $s)
                    <tr class="border-b border-slate-50">
                        <td class="px-5 py-2.5"><a href="{{ route('pos.receipt', $s) }}" class="text-brand-600 hover:underline whitespace-nowrap">{{ $s->invoice_number }}</a>
                            <span class="block text-xs text-slate-400">{{ $s->created_at->isToday() ? $s->created_at->format('g:i A') : $s->created_at->format('M j, g:i A') }}</span></td>
                        <td class="px-2 text-slate-600">{{ $s->customer->name ?? 'Walk-in' }}</td>
                        <td class="px-2 text-slate-600">{{ $s->user->name ?? '-' }}</td>
                        <td class="px-2 text-right">{{ (int) $s->pieces }}</td>
                        <td class="px-2 text-right whitespace-nowrap">{{ $peso($s->total_amount) }}</td>
                        <td class="px-5"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $statusStyle[$s->status] ?? 'bg-slate-100 text-slate-600' }}">{{ ucfirst($s->status) }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-10 text-center text-slate-400">No sales recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="space-y-6">
        {{-- What's running low --}}
        <div class="{{ $card }} p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-ink">Running low</p>
                <span class="text-xs {{ $outOfStockCount ? 'text-red-600' : ($lowStockCount ? 'text-amber-600' : 'text-slate-400') }}">{{ $lowStockCount }} low · {{ $outOfStockCount }} out</span>
            </div>
            <ul class="space-y-2.5 text-sm">
                @forelse($low->take(6) as $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="min-w-0"><span class="block truncate text-slate-700">{{ $row->name }}</span><span class="block text-xs text-slate-400">{{ $row->kind }}</span></span>
                        <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $row->out ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800' }}">
                            {{ $row->out ? 'Out' : \App\Services\Inventory\StockAlertService::formatQty($row->on_hand) . ' ' . $row->unit . ' left' }}
                        </span>
                    </li>
                @empty
                    <li class="flex items-center gap-2 text-emerald-700"><i data-lucide="check-circle-2" class="w-4 h-4"></i> Everything is stocked.</li>
                @endforelse
            </ul>
            @if($low->count() > 6)
                <a href="{{ route('reports.sales', ['period' => 'month']) }}" class="block mt-3 text-xs text-brand-600 hover:underline">{{ $low->count() - 6 }} more on the Sales Report →</a>
            @endif
        </div>

        {{-- The latest stock changes --}}
        <div class="{{ $card }} p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-ink">Recent stock activity</p>
                <a href="{{ route('inventory.index') }}" class="text-xs text-brand-600 hover:underline">Products →</a>
            </div>
            <div class="space-y-3">
                @forelse($recentMovements as $mv)
                    <div class="flex items-center gap-3 text-sm">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 {{ $mv->quantity >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-brand-50 text-brand-600' }}">
                            <i data-lucide="{{ $mv->quantity >= 0 ? 'package-plus' : 'package-minus' }}" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-ink truncate">{{ $mv->variant->product->name ?? 'Product' }} ({{ $mv->variant->variant_name ?? '-' }}): {{ $mv->quantity >= 0 ? '+' : '' }}{{ $mv->quantity }}</p>
                            <p class="text-xs text-slate-400">{{ $mv->created_at->diffForHumans() }}{{ $mv->user ? ' · ' . $mv->user->name : '' }}</p>
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
const chartSets = @json($chartSets);
const peso = (v) => '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const salesChart = new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: { labels: [], datasets: [{ data: [], backgroundColor: '#1f8ba0', hoverBackgroundColor: '#146c84', borderRadius: 6, maxBarThickness: 48 }] },
    options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => peso(ctx.parsed.y) } } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b', callback: (v) => '₱' + Number(v).toLocaleString('en-PH') } },
            x: { grid: { display: false }, ticks: { color: '#64748b' } },
        },
    },
});

// A period with no sales says so, instead of an empty grid that looks broken.
window.setChartPeriod = function (period) {
    const set = chartSets[period];
    const total = set.data.reduce((a, b) => a + Number(b), 0);
    salesChart.data.labels = set.labels;
    salesChart.data.datasets[0].data = set.data;
    salesChart.update();
    document.getElementById('chart-empty').style.display = total > 0 ? 'none' : 'flex';
    document.getElementById('chart-total').textContent = peso(total) + ' in completed sales';
};
setChartPeriod('weekly');

@if($categories->isNotEmpty())
new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: { labels: @json($categories->pluck('name')), datasets: [{ data: @json($categories->pluck('revenue')->map(fn ($v) => (float) $v)),
        backgroundColor: ['#146c84', '#e0a526', '#1f8ba0', '#10b981', '#6366f1', '#f43f5e', '#0ea5e9', '#94a3b8'], borderWidth: 0 }] },
    options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.label + ': ' + peso(ctx.parsed) } } } },
});
@endif
</script>
@endsection
