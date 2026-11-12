@extends('layouts.app')
@section('title', 'Sales Report')

@push('scripts-head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" integrity="sha384-DhxhYObIMeMNGyAG7iK11OHzBIKyEIeRL0ad1iFPAOwZB8iirUlTT0O/WJJUk8+o" crossorigin="anonymous"></script>
@endpush

@section('content')
@php
    $totals = $report->totals();
    $series = $report->series();
    $payments = $report->payments();
    $received = $payments->sum('total');
    $categories = $report->byCategory();
    $staff = $report->byStaff();
    $hours = $report->busiestHours();
    $items = $report->byItem();
    $here = ['period' => $report->period, 'date' => $report->start->toDateString()];
    $card = 'bg-white rounded-xl border border-slate-200';
    $bar = fn ($value, $max) => $max > 0 ? max(2, round($value / $max * 100)) : 0;
@endphp
<div class="space-y-6">
    <p class="text-sm text-slate-500">
        What sold and for how much. A sale counts once it's completed, on the day it was rung up. <strong class="font-medium text-slate-700">Money received</strong>
        is every payment taken in this time, down payments and balances paid later included.
    </p>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex gap-1 rounded-xl bg-white border border-slate-200 p-1">
            @foreach(\App\Services\Sales\SalesReport::PERIODS as $value => $text)
                <a href="{{ route('reports.sales', ['period' => $value, 'date' => $report->start->toDateString()]) }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium {{ $report->period === $value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">{{ $text }}</a>
            @endforeach
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('reports.sales', ['period' => $report->period, 'date' => $report->previous()]) }}" title="The one before" aria-label="The one before"
               class="w-9 h-9 rounded-lg border border-slate-300 bg-white flex items-center justify-center text-slate-600 hover:bg-slate-50"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
            <p class="min-w-[12rem] text-center text-sm font-semibold text-ink">{{ $report->label() }}</p>
            @if($report->next())
                <a href="{{ route('reports.sales', ['period' => $report->period, 'date' => $report->next()]) }}" title="The one after" aria-label="The one after"
                   class="w-9 h-9 rounded-lg border border-slate-300 bg-white flex items-center justify-center text-slate-600 hover:bg-slate-50"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
            @else
                <span class="w-9 h-9"></span>
            @endif
            <a href="{{ route('reports.sales.download', $here) }}" title="Save this whole report as a spreadsheet"
               class="ml-2 text-xs px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5 bg-white hover:bg-slate-50">
                <i data-lucide="download" class="w-3.5 h-3.5"></i> Download
            </a>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">Sales total</p>
            <p class="text-2xl font-semibold text-ink mt-1">₱{{ number_format($totals['revenue'], 2) }}</p>
            <p class="text-xs text-slate-400">{{ $totals['discounts'] > 0 ? '₱' . number_format($totals['discounts'], 2) . ' in discounts' : 'no discounts' }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">Completed sales</p>
            <p class="text-2xl font-semibold text-ink mt-1">{{ $totals['count'] }}</p>
            <p class="text-xs text-slate-400">₱{{ number_format($totals['average'], 2) }} on average</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">Money received</p>
            <p class="text-2xl font-semibold text-emerald-600 mt-1">₱{{ number_format($received, 2) }}</p>
            <p class="text-xs text-slate-400">{{ $payments->sum('count') }} {{ $payments->sum('count') == 1 ? 'payment' : 'payments' }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">How customers paid</p>
            @foreach(\App\Models\SalePayment::METHODS as $method => $text)
                <p class="flex justify-between text-sm mt-1"><span class="text-slate-600">{{ $text }}</span><span class="font-medium text-ink">₱{{ number_format($payments[$method]->total ?? 0, 2) }}</span></p>
            @endforeach
        </div>
    </div>

    <div class="{{ $card }} p-5">
        <p class="font-semibold text-ink mb-4">{{ ['day' => 'Sales by hour', 'year' => 'Sales by month'][$report->period] ?? 'Sales by day' }}</p>
        <div class="h-64"><canvas id="series-chart" aria-label="Sales chart" role="img"></canvas></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @php $best = $report->bestSellers(); $max = (float) $best->max('quantity'); @endphp
        <div class="{{ $card }} p-5">
            <p class="font-semibold text-ink mb-3">Best sellers</p>
            <ul class="space-y-3 text-sm">
                @forelse($best as $row)
                    <li>
                        <div class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $row->name }}</span><span class="whitespace-nowrap text-slate-500">{{ (int) $row->quantity }} sold</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ $bar((float) $row->quantity, $max) }}%"></div></div>
                    </li>
                @empty
                    <li class="text-slate-400">Nothing sold.</li>
                @endforelse
            </ul>
        </div>
        @php $max = (float) $categories->max('revenue'); @endphp
        <div class="{{ $card }} p-5">
            <p class="font-semibold text-ink mb-3">By category <span class="text-xs font-normal text-slate-400">(before discounts)</span></p>
            <ul class="space-y-3 text-sm">
                @forelse($categories as $row)
                    <li>
                        <div class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $row->name }}</span><span class="whitespace-nowrap text-slate-500">₱{{ number_format($row->revenue, 2) }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-gold-500" style="width: {{ $bar((float) $row->revenue, $max) }}%"></div></div>
                    </li>
                @empty
                    <li class="text-slate-400">Nothing sold.</li>
                @endforelse
            </ul>
        </div>
        @php $max = (float) $staff->max('revenue'); @endphp
        <div class="{{ $card }} p-5">
            <p class="font-semibold text-ink mb-3">What each person sold</p>
            <ul class="space-y-3 text-sm">
                @forelse($staff as $row)
                    <li>
                        <div class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $row->name }}</span><span class="whitespace-nowrap text-slate-500">₱{{ number_format($row->revenue, 2) }} · {{ $row->count }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ $bar((float) $row->revenue, $max) }}%"></div></div>
                    </li>
                @empty
                    <li class="text-slate-400">Nothing sold.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 {{ $card }}">
            <div class="px-5 py-4 border-b border-slate-100"><p class="font-semibold text-ink">Revenue by item</p></div>
            <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-white">
                    <tr class="text-left text-slate-500 border-b border-slate-100">
                        <th class="px-5 py-3">Item</th><th class="px-2">SKU</th><th class="px-2 text-right">Sold</th><th class="px-5 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $row)
                    <tr class="border-b border-slate-50">
                        <td class="px-5 py-2.5 text-slate-700">{{ $row->name }}@if($row->variant_name) <span class="text-slate-400">({{ $row->variant_name }})</span>@endif</td>
                        <td class="px-2 text-xs text-slate-400">{{ $row->sku ?? '-' }}</td>
                        <td class="px-2 text-right">{{ (int) $row->quantity }}</td>
                        <td class="px-5 text-right whitespace-nowrap">₱{{ number_format($row->revenue, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="p-8 text-center text-sm text-slate-400">Nothing sold.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        @php $max = (float) $hours->max(); @endphp
        <div class="{{ $card }} p-5 self-start">
            <p class="font-semibold text-ink mb-3">Busiest times</p>
            <ul class="space-y-3 text-sm">
                @forelse($hours->take(8) as $hour => $count)
                    <li>
                        <div class="flex justify-between gap-3"><span class="text-slate-700">{{ $report->hourLabel((int) $hour) }} – {{ $report->hourLabel(((int) $hour + 1) % 24) }}</span><span class="text-slate-500">{{ $count }} {{ $count == 1 ? 'sale' : 'sales' }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-400" style="width: {{ $bar((float) $count, $max) }}%"></div></div>
                    </li>
                @empty
                    <li class="text-slate-400">Nothing sold.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('series-chart'), {
    type: 'bar',
    data: { labels: @json($series['labels']), datasets: [{ data: @json($series['values']), backgroundColor: '#1f8ba0', borderRadius: 6 }] },
    options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => '₱' + c.parsed.y.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => '₱' + Number(v).toLocaleString('en-PH') } }, x: { grid: { display: false } } },
    },
});
</script>
@endsection
