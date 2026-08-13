@extends('layouts.app')
@section('title', 'Sales')

@section('content')
<div x-data="{ editingSale: null, confirmingArchiveSale: null }">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Every transaction rung up through Billing/POS shows up here — this is the single record of what's been sold.
            </p>
        </div>
        <a href="{{ route('sales.archive') }}" class="text-xs px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5 flex-shrink-0">
            <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedSalesCount + $archivedOrdersCount }})
        </a>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
        <form method="GET" class="flex flex-wrap gap-3 p-4 border-b border-slate-100 dark:border-slate-700">
            <x-date-picker name="date_from" :selected="request('date_from')" placeholder="From date" />
            <x-date-picker name="date_to" :selected="request('date_to')" placeholder="To date" />
            <div class="w-56">
                <x-select-menu name="status"
                    :options="['' => 'All statuses', 'pending' => 'Pending (down payment)', 'processing' => 'Processing (paid, in progress)', 'completed' => 'Completed', 'voided' => 'Voided']"
                    :selected="request('status')"
                    placeholder="All statuses" />
            </div>
            <button class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg px-4 py-2">Filter</button>
        </form>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                    <th class="p-4">Invoice</th><th>Customer</th><th>Cashier</th><th>Items</th>
                    <th class="text-right">Total</th><th class="text-right">Balance</th><th>Payment</th><th>Status</th><th>Date</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                @php
                    $badge = match($sale->status) {
                        'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                        'processing' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                        'pending' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                        'voided' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                        default => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
                    };
                    $balance = max($sale->total_amount - $sale->amount_paid, 0);
                    $saleEditPayload = [
                        'id' => $sale->id,
                        'invoice_number' => $sale->invoice_number,
                        'status' => $sale->status,
                        'balance' => $balance,
                        'update_url' => route('sales.update', $sale),
                    ];
                @endphp
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-4 font-medium text-ink dark:text-white">{{ $sale->invoice_number }}</td>
                    <td>{{ $sale->customer->name ?? 'Walk-in' }}</td>
                    <td>{{ $sale->user->name ?? '-' }}</td>
                    <td>{{ $sale->items->count() }}</td>
                    <td class="text-right">₱{{ number_format($sale->total_amount, 2) }}</td>
                    <td class="text-right {{ $balance > 0 ? 'text-amber-600 font-medium' : 'text-slate-400' }}">₱{{ number_format($balance, 2) }}</td>
                    <td class="capitalize">{{ str_replace('_',' ',$sale->payment_method) }}</td>
                    <td><span class="px-2 py-1 rounded-full text-xs font-medium {{ $badge }}">{{ ucfirst($sale->status) }}</span></td>
                    <td class="text-slate-500 dark:text-slate-400">{{ $sale->created_at->format('M j, h:i A') }}</td>
                    <td class="whitespace-nowrap">
                        <button type="button"
                            onclick='openSaleEditor(@json($saleEditPayload))'
                            class="inline-flex items-center gap-1 text-xs bg-brand-50 dark:bg-brand-600/20 text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-600/30 rounded-lg px-2.5 py-1.5 mr-1.5">
                            <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                        </button>
                        @if($sale->status === 'processing')
                        <form method="POST" action="{{ route('sales.complete', $sale) }}" class="inline">
                            @csrf
                            <button class="inline-flex items-center gap-1 text-xs bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 rounded-lg px-2.5 py-1.5 mr-1.5">
                                <i data-lucide="check" class="w-3 h-3"></i> Mark Completed
                            </button>
                        </form>
                        @endif
                        @if(in_array($sale->status, ['pending', 'processing']))
                        <form method="POST" action="{{ route('sales.void', $sale) }}" class="inline mr-1.5" onsubmit="return confirm('Void this sale and restore stock?')">
                            @csrf
                            <button class="inline-flex items-center gap-1 text-xs bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg px-2.5 py-1.5">
                                <i data-lucide="ban" class="w-3 h-3"></i> Void
                            </button>
                        </form>
                        @endif
                        <button type="button" title="Archive this sale"
                            @click="confirmingArchiveSale = { id: {{ $sale->id }}, invoice: {{ json_encode($sale->invoice_number) }} }"
                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg border border-slate-200 dark:border-slate-600 text-slate-500 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700">
                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                        </button>
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" class="p-8 text-center text-sm text-slate-400">No sales recorded yet — ring one up through Billing/POS.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $sales->links() }}</div>
    </div>

    {{-- EDIT SALE MODAL --}}
    <div x-show="editingSale !== null" style="display:none;" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="editingSale = null">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm p-6" x-show="editingSale !== null" x-transition.scale>
            <div class="flex items-center justify-between mb-4">
                <p class="font-semibold text-ink dark:text-white flex items-center gap-2"><i data-lucide="pencil" class="w-4 h-4"></i> Edit Sale <span x-text="editingSale?.invoice_number"></span></p>
                <button @click="editingSale = null" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form :action="editingSale?.update_url" method="POST" class="space-y-3">
                @csrf
                @method('PATCH')
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Status</label>
                    <select name="status" x-model="editingSale.status" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                        <option value="pending">Pending (down payment)</option>
                        <option value="processing">Processing (paid, in progress)</option>
                        <option value="completed">Completed</option>
                        <option value="voided">Voided</option>
                    </select>
                </div>
                <div x-show="editingSale?.balance > 0">
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">
                        Record additional payment <span class="text-slate-400">(balance: ₱<span x-text="editingSale?.balance?.toFixed(2)"></span>)</span>
                    </label>
                    <input type="number" name="additional_payment" step="0.01" min="0" placeholder="0.00"
                           class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="editingSale = null" class="text-sm text-slate-500 dark:text-slate-400 px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-brand-600 text-white text-sm rounded-lg px-5 py-2">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    {{-- SHADCN-STYLE ALERT DIALOG — Archive confirmation --}}
    <div x-show="confirmingArchiveSale !== null" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingArchiveSale = null">
        <div x-show="confirmingArchiveSale !== null" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6">
            <p class="text-lg font-semibold text-ink dark:text-white mb-2">Archive this sale?</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                <span x-text="confirmingArchiveSale?.invoice"></span> will be moved to the Archive, not permanently deleted — you can restore it from there any time.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="confirmingArchiveSale = null"
                    class="border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2">
                    Cancel
                </button>
                <button @click="document.getElementById('archive-sale-form-' + confirmingArchiveSale.id).submit()"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">
                    Archive
                </button>
            </div>
        </div>
    </div>

    @foreach($sales as $sale)
        <form id="archive-sale-form-{{ $sale->id }}" method="POST" action="{{ route('sales.archiveOne', $sale) }}" class="hidden">
            @csrf
        </form>
    @endforeach
</div>

<script>
function openSaleEditor(sale) {
    const root = document.querySelector('[x-data*="editingSale"]');
    if (root && window.Alpine) {
        window.Alpine.$data(root).editingSale = sale;
    }
}
</script>
@endsection
