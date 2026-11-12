@extends('layouts.app')
@section('title', 'Sales Report')

@push('scripts-head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" integrity="sha384-DhxhYObIMeMNGyAG7iK11OHzBIKyEIeRL0ad1iFPAOwZB8iirUlTT0O/WJJUk8+o" crossorigin="anonymous"></script>
@endpush

@section('content')
@php
    use App\Services\Inventory\StockAlertService;
    $totals = $report->totals();
    $series = $report->series();
    $payments = $report->payments();
    $received = $payments->sum('total');
    $categories = $report->byCategory();
    $people = $report->byStaff();
    $hours = $report->busiestHours();
    $items = $report->byItem();
    $used = $report->materialsUsed();
    $waste = $report->waste();
    $rejected = $report->rejected();
    $low = $report->lowStock();
    $staffName = $report->staffName();
    $here = array_filter(['period' => $report->period, 'date' => $report->start->toDateString(), 'user' => $report->userId]);
    $card = 'bg-white rounded-xl border border-slate-200';
    $bar = fn ($value, $max) => $max > 0 ? max(2, round($value / $max * 100)) : 0;
    $peso = fn ($value) => '₱' . number_format((float) $value, 2);
    $qty = fn ($value) => StockAlertService::formatQty($value);
@endphp
<div class="space-y-6">
    <p class="text-sm text-slate-500">
        What sold, who sold it, and for how much. A sale counts once it's completed, on the day it was rung up. <strong class="font-medium text-slate-700">Money received</strong>
        is every payment taken in this time, down payments and balances paid later included.
    </p>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex gap-1 rounded-xl bg-white border border-slate-200 p-1">
                @foreach(\App\Services\Sales\SalesReport::PERIODS as $value => $text)
                    <a href="{{ route('reports.sales', ['period' => $value] + $here) }}"
                       class="px-4 py-2 rounded-lg text-sm font-medium {{ $report->period === $value ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">{{ $text }}</a>
                @endforeach
            </div>
            {{-- One person's sales only: everything below narrows to what they rang up --}}
            <form method="GET" action="{{ route('reports.sales') }}">
                <input type="hidden" name="period" value="{{ $report->period }}">
                <input type="hidden" name="date" value="{{ $report->start->toDateString() }}">
                <label class="sr-only" for="report-user">Staff</label>
                <select id="report-user" name="user" onchange="this.form.requestSubmit()"
                        class="rounded-lg border text-sm px-3 py-2 pr-9 {{ $report->userId ? 'bg-brand-50 border-brand-400 text-brand-700 font-medium' : 'bg-white border-slate-300 text-slate-600' }}">
                    <option value="">Everyone</option>
                    @foreach($staff as $person)
                        <option value="{{ $person->id }}" @selected($report->userId === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('reports.sales', ['date' => $report->previous()] + $here) }}" title="The one before" aria-label="The one before"
               class="w-9 h-9 rounded-lg border border-slate-300 bg-white flex items-center justify-center text-slate-600 hover:bg-slate-50"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
            <p class="min-w-[12rem] text-center text-sm font-semibold text-ink">{{ $report->label() }}</p>
            @if($report->next())
                <a href="{{ route('reports.sales', ['date' => $report->next()] + $here) }}" title="The one after" aria-label="The one after"
                   class="w-9 h-9 rounded-lg border border-slate-300 bg-white flex items-center justify-center text-slate-600 hover:bg-slate-50"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
            @else
                <span class="w-9 h-9"></span>
            @endif
        </div>
    </div>

    {{-- Taking the report out: Excel, CSV, or a Google Sheet that keeps itself up to date --}}
    <div class="{{ $card }} p-4" x-data="{ sheets: @js(session()->has('sheet_link')) }">
        <div class="flex flex-wrap items-center gap-2">
            <p class="text-sm font-medium text-ink mr-auto">Save or share this report{{ $staffName ? " ({$staffName} only)" : '' }}</p>
            <a href="{{ route('reports.sales.download', ['format' => 'xlsx'] + $here) }}" data-no-skeleton
               class="text-sm px-4 py-2 rounded-lg flex items-center gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700">
                <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> Excel file
            </a>
            <a href="{{ route('reports.sales.download', ['format' => 'csv'] + $here) }}" data-no-skeleton
               class="text-sm px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5 bg-white hover:bg-slate-50">
                <i data-lucide="download" class="w-4 h-4"></i> CSV file
            </a>
            <button type="button" @click="sheets = !sheets" :aria-expanded="sheets"
                    class="text-sm px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5 bg-white hover:bg-slate-50">
                <i data-lucide="sheet" class="w-4 h-4"></i> Google Sheets
            </button>
        </div>
        <div x-show="sheets" style="display:none;" class="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-600 space-y-3">
            @if(session('sheet_link'))
                <div x-data="{ table: 'items', period: 'month', copied: false, link: @js(session('sheet_link')),
                              get formula() { return `=IMPORTDATA(&quot;${this.link}?table=${this.table}&amp;period=${this.period}&quot;)` } }"
                     class="rounded-lg bg-amber-50 border border-amber-200 p-4 space-y-3">
                    <p class="font-medium text-amber-800">Copy this now. It is shown only this once.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">What to bring in
                            <select x-model="table" class="mt-1 w-full rounded-lg border border-slate-300 bg-white text-sm px-3 py-2">
                                @foreach(\App\Services\Sales\SalesReport::TABLES as $key => $title)
                                    <option value="{{ $key }}">{{ $title }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">For
                            <select x-model="period" class="mt-1 w-full rounded-lg border border-slate-300 bg-white text-sm px-3 py-2">
                                <option value="day">Today</option><option value="week">This week</option>
                                <option value="month">This month</option><option value="year">This year</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <input type="text" readonly :value="formula" aria-label="Formula for Google Sheets" class="flex-1 min-w-0 rounded-lg border border-slate-300 text-xs font-mono bg-white px-3 py-2">
                        <button type="button" @click="navigator.clipboard.writeText(formula); copied = true; setTimeout(() => copied = false, 2000)"
                                class="px-4 rounded-lg bg-ink text-white text-sm" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                    </div>
                    <p>In Google Sheets, click cell <strong>A1</strong>, paste, and press <strong>Enter</strong>. Google updates it about once an hour. For another table, pick it above, copy again and paste it on a new tab.</p>
                </div>
            @endif
            <p>A Google Sheet can bring in this report and keep it up to date by itself. Press <strong>Make a link</strong>, copy what it shows, and paste it into the sheet.
                Anyone with the link can see the sales, so share the sheet only with people who should.</p>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('reports.sheet-link') }}">
                    @csrf
                    <button class="px-4 py-2 rounded-lg bg-brand-600 text-white hover:bg-brand-700">{{ $sheetLinkOn ? 'Make a new link' : 'Make a link' }}</button>
                </form>
                @if($sheetLinkOn)
                    <form method="POST" action="{{ route('reports.sheet-link.destroy') }}">
                        @csrf @method('DELETE')
                        <button class="px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">Turn the link off</button>
                    </form>
                    <p class="self-center text-xs text-slate-400">Your link is on. A new link turns the old one off.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">Sales total</p>
            <p class="text-2xl font-semibold text-ink mt-1">{{ $peso($totals['revenue']) }}</p>
            <p class="text-xs text-slate-400">{{ $totals['discounts'] > 0 ? $peso($totals['discounts']) . ' in discounts' : 'no discounts' }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">Completed sales</p>
            <p class="text-2xl font-semibold text-ink mt-1">{{ $totals['count'] }}</p>
            <p class="text-xs text-slate-400">{{ $peso($totals['average']) }} on average · {{ $totals['items'] }} {{ $totals['items'] == 1 ? 'item' : 'items' }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">Money received</p>
            <p class="text-2xl font-semibold text-emerald-600 mt-1">{{ $peso($received) }}</p>
            <p class="text-xs text-slate-400">{{ $payments->sum('count') }} {{ $payments->sum('count') == 1 ? 'payment' : 'payments' }}</p>
        </div>
        <div class="{{ $card }} p-5">
            <p class="text-xs font-medium text-slate-500">How customers paid</p>
            @foreach(\App\Models\SalePayment::METHODS as $method => $text)
                <p class="flex justify-between text-sm mt-1"><span class="text-slate-600">{{ $text }}</span><span class="font-medium text-ink">{{ $peso($payments[$method]->total ?? 0) }}</span></p>
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
                        <div class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $row->name }}</span><span class="whitespace-nowrap text-slate-500">{{ $peso($row->revenue) }}</span></div>
                        <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-gold-500" style="width: {{ $bar((float) $row->revenue, $max) }}%"></div></div>
                    </li>
                @empty
                    <li class="text-slate-400">Nothing sold.</li>
                @endforelse
            </ul>
        </div>
        @php $max = (float) $hours->max(); @endphp
        <div class="{{ $card }} p-5">
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

    {{-- Each person: how much they made and what they sold --}}
    <div class="{{ $card }}">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold text-ink">What each person sold</p>
            @if($staffName)
                <a href="{{ route('reports.sales', ['period' => $report->period, 'date' => $report->start->toDateString()]) }}" class="text-xs text-brand-600 hover:underline">Showing {{ $staffName }} only · Show everyone</a>
            @endif
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="px-5 py-3">Staff</th><th class="px-2 text-right">Sales</th><th class="px-2 text-right">Items sold</th>
                    <th class="px-2 text-right">Revenue</th><th class="px-2 text-right">Average sale</th><th class="px-5"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($people as $person)
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-2.5 text-slate-700 font-medium">{{ $person->name }}</td>
                    <td class="px-2 text-right">{{ $person->count }}</td>
                    <td class="px-2 text-right">{{ $person->items }}</td>
                    <td class="px-2 text-right whitespace-nowrap">{{ $peso($person->revenue) }}</td>
                    <td class="px-2 text-right whitespace-nowrap text-slate-500">{{ $peso($person->average) }}</td>
                    <td class="px-5 text-right">
                        @unless($report->userId)
                            <a href="{{ route('reports.sales', ['user' => $person->id] + $here) }}" class="text-xs text-brand-600 hover:underline whitespace-nowrap">See what they sold</a>
                        @endunless
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="p-8 text-center text-sm text-slate-400">Nothing sold.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 {{ $card }} self-start">
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
                        <td class="px-5 text-right whitespace-nowrap">{{ $peso($row->revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="p-8 text-center text-sm text-slate-400">Nothing sold.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        {{-- As of now, whatever period is shown --}}
        <div class="{{ $card }} p-5 self-start">
            <p class="font-semibold text-ink">Running low now</p>
            <p class="text-xs text-slate-400 mb-3">At or under its reorder point, as of today</p>
            <ul class="space-y-2.5 text-sm max-h-[24rem] overflow-y-auto">
                @forelse($low as $row)
                    <li class="flex justify-between gap-3">
                        <span class="min-w-0"><span class="block truncate text-slate-700">{{ $row->name }}</span><span class="block text-xs text-slate-400">{{ $row->kind }}</span></span>
                        <span class="whitespace-nowrap text-right {{ $row->out ? 'text-red-600 font-medium' : 'text-amber-600' }}">{{ $row->out ? 'Out' : $qty($row->on_hand) . ' ' . $row->unit }}<span class="block text-xs text-slate-400 font-normal">reorder at {{ $qty($row->reorder_at) }}</span></span>
                    </li>
                @empty
                    <li class="text-slate-400">Nothing is running low.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Stock behind the sales: the two material types used, and what was lost --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @foreach(['continuous' => 'Continuous raw materials used', 'discrete' => 'Discrete materials used'] as $type => $title)
            @php $rows = $used->filter(fn ($m) => ($m->inventory_type === 'continuous') === ($type === 'continuous')); @endphp
            <div class="{{ $card }} p-5 self-start">
                <div class="flex justify-between gap-3 mb-3"><p class="font-semibold text-ink">{{ $title }}</p><p class="text-sm text-slate-500 whitespace-nowrap">{{ $peso($rows->sum('cost')) }}</p></div>
                <ul class="space-y-2.5 text-sm">
                    @forelse($rows as $row)
                        <li class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $row->name }}</span><span class="whitespace-nowrap text-slate-500">{{ $qty($row->quantity) }} {{ $row->unit }} · {{ $peso($row->cost) }}</span></li>
                    @empty
                        <li class="text-slate-400">None used.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
        <div class="{{ $card }} p-5 self-start">
            <div class="flex justify-between gap-3 mb-3"><p class="font-semibold text-ink">Waste and rejected runs</p><p class="text-sm text-red-600 whitespace-nowrap">{{ $peso($waste->sum('cost')) }}</p></div>
            <ul class="space-y-2.5 text-sm">
                @foreach($waste as $row)
                    <li class="flex justify-between gap-3"><span class="truncate text-slate-700">{{ $row->name }} <span class="text-slate-400">({{ $row->what }})</span></span><span class="whitespace-nowrap text-slate-500">{{ $qty($row->quantity) }} {{ $row->unit }} · {{ $peso($row->cost) }}</span></li>
                @endforeach
                @if($rejected->isNotEmpty())
                    <li class="pt-2 border-t border-slate-100 text-slate-600">
                        {{ $rejected->count() }} rejected {{ $rejected->count() == 1 ? 'run' : 'runs' }}, {{ $rejected->sum('quantity') }} pcs, {{ $peso($rejected->sum('material_cost')) }} in materials.
                        @if($rejected->sum('scrap_revenue') > 0) {{ $peso($rejected->sum('scrap_revenue')) }} back from scrap sold. @endif
                        <a href="{{ route('stock.rejected', ['month' => $report->start->format('Y-m')]) }}" class="text-brand-600 hover:underline">See them</a>
                    </li>
                @endif
                @if($waste->isEmpty() && $rejected->isEmpty())
                    <li class="text-slate-400">Nothing lost.</li>
                @endif
            </ul>
        </div>
    </div>

    {{-- Every sale in this time (one person's, when one is picked) --}}
    <div class="{{ $card }}">
        <div class="px-5 py-4 border-b border-slate-100"><p class="font-semibold text-ink">Every sale{{ $staffName ? " by {$staffName}" : '' }}</p></div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="px-5 py-3">When</th><th class="px-2">Invoice</th><th class="px-2">Staff</th><th class="px-2">Customer</th>
                    <th class="px-2 text-right">Items</th><th class="px-5 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-2.5 whitespace-nowrap text-slate-500">{{ \Carbon\Carbon::parse($sale->created_at)->format($report->period === 'day' ? 'g:i A' : 'M j, g:i A') }}</td>
                    <td class="px-2"><a href="{{ route('pos.receipt', $sale->id) }}" class="text-brand-600 hover:underline whitespace-nowrap">{{ $sale->invoice_number }}</a></td>
                    <td class="px-2 text-slate-700">{{ $sale->staff }}</td>
                    <td class="px-2 text-slate-500">{{ $sale->customer ?? '-' }}</td>
                    <td class="px-2 text-right">{{ (int) $sale->items }}</td>
                    <td class="px-5 text-right whitespace-nowrap">{{ $peso($sale->total_amount) }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="p-8 text-center text-sm text-slate-400">Nothing sold.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if($sales->hasPages())<div class="p-4">{{ $sales->links() }}</div>@endif
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
