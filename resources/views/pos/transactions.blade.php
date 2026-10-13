@extends('layouts.app')
@section('title', 'Sales')

@section('content')
@php
    // Each filter applies as soon as it changes, so there is no Filter button.
    $applyFilter = "\$el.closest('form').requestSubmit()";
    // Row actions are icons only; title and aria-label name each one.
    $iconBtn = 'inline-flex items-center justify-center w-8 h-8 rounded-lg';
    $isOwner = auth()->user()->role === 'admin';
@endphp
<div x-data="{ editingSale: null, confirmingArchiveSale: null, askingVoid: null }">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <p class="text-sm text-slate-500">
                Every transaction rung up through Billing/POS shows up here — this is the single record of what's been sold.
            </p>
        </div>
        {{-- The archive is the owner's: an archived sale leaves the sales totals --}}
        @if($isOwner)
        <a href="{{ route('sales.archive') }}" class="text-xs px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5 flex-shrink-0">
            <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedSalesCount + $archivedOrdersCount }})
        </a>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
        <form method="GET" class="flex flex-wrap gap-3 p-4 border-b border-slate-100">
            {{-- Searches as you type. The page reloads with the results, so the box takes the cursor back. --}}
            <div class="relative">
                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Invoice, customer or phone..." autocomplete="off" aria-label="Search sales"
                    @input.debounce.400ms="$el.form.requestSubmit()"
                    x-data x-init="if ($el.value) { $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length) }"
                    class="text-sm rounded-md border border-slate-300 bg-white pl-9 pr-3 py-2 w-60 shadow-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <x-date-picker name="date_from" :selected="request('date_from')" placeholder="From date" :onchange="$applyFilter" />
            <x-date-picker name="date_to" :selected="request('date_to')" placeholder="To date" :onchange="$applyFilter" />
            <div class="w-56">
                <x-select-menu name="status"
                    :options="['' => 'All statuses', 'pending' => 'Pending (down payment)', 'processing' => 'Processing (paid, in progress)', 'on_order' => 'On order (out of stock)', 'completed' => 'Completed', 'voided' => 'Voided']"
                    :selected="request('status')"
                    placeholder="All statuses"
                    :onchange="$applyFilter" />
            </div>
        </form>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="p-4">Invoice</th><th class="px-2">Customer</th><th class="px-2">Cashier</th><th class="px-2">Items</th>
                    <th class="px-2 text-right">Total</th><th class="px-2">Payment</th><th class="px-2">Status</th><th class="px-2">Date</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                @php
                    $badge = match($sale->status) {
                        'completed' => 'bg-emerald-100 text-emerald-700',
                        'processing' => 'bg-blue-100 text-blue-700',
                        'pending' => 'bg-amber-100 text-amber-700',
                        'voided' => 'bg-red-100 text-red-700',
                        default => 'bg-slate-100 text-slate-600',
                    };
                    $balance = max($sale->total_amount - $sale->amount_paid, 0);
                    // Sold while out of stock: items still waiting for it, and ones whose stock came in
                    // (they can be made now). Only an open sale waits.
                    $open = in_array($sale->status, ['pending', 'processing']);
                    $onOrder = $open ? $sale->items->filter->isOnOrder()->sum('quantity') : 0;
                    $stockIn = $open && ! $onOrder && $sale->items->contains(fn ($i) => $i->backordered_at);
                    $saleEditPayload = [
                        'id' => $sale->id,
                        'invoice_number' => $sale->invoice_number,
                        'status' => $sale->status,
                        'balance' => $balance,
                        'update_url' => route('sales.update', $sale),
                    ];
                @endphp
                <tr class="border-b border-slate-50">
                    <td class="p-4 font-medium text-ink whitespace-nowrap">{{ $sale->invoice_number }}</td>
                    <td class="px-2">
                        {{ $sale->customer->name ?? 'Walk-in' }}
                        @if($sale->customer?->phone)
                            <span class="block text-xs text-slate-400">{{ $sale->customer->phone }}</span>
                        @endif
                    </td>
                    <td class="px-2">{{ $sale->user->name ?? '-' }}</td>
                    {{-- What was sold: the first two lines, then how many more --}}
                    <td class="px-2 text-xs text-slate-600" style="max-width:14rem;">
                        @foreach($sale->items->take(2) as $line)
                            <span class="block leading-snug" title="{{ $line->item_name }}{{ $line->variant_name ? ' (' . $line->variant_name . ')' : '' }} x{{ $line->quantity }}">
                                {{ $line->item_name }}@if($line->variant_name) <span class="text-slate-400">({{ $line->variant_name }})</span>@endif <span class="text-slate-400">x{{ $line->quantity }}</span>@if($open && $line->isOnOrder()) <span class="text-amber-600">· on order</span>@endif
                            </span>
                        @endforeach
                        @if($sale->items->count() > 2)
                            <span class="block text-slate-400">+{{ $sale->items->count() - 2 }} more</span>
                        @endif
                    </td>
                    {{-- What's still owed sits under the total, so the table fits a laptop screen --}}
                    <td class="px-2 text-right whitespace-nowrap">
                        ₱{{ number_format($sale->total_amount, 2) }}
                        @if($balance > 0)
                            <span class="block text-xs font-medium text-amber-600">₱{{ number_format($balance, 2) }} due</span>
                        @endif
                    </td>
                    <td class="px-2 capitalize">{{ $sale->payment_method === 'gcash' ? 'GCash' : str_replace('_',' ',$sale->payment_method) }}</td>
                    <td class="px-2">
                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $badge }}">{{ ucfirst($sale->status) }}</span>
                        @if(isset($voidRequested[$sale->id]))
                            <a href="{{ route('approvals.index') }}" class="block mt-1.5 text-xs font-medium text-red-600 whitespace-nowrap hover:underline"
                               title="A cashier asked to void it: waiting for an owner's approval">Void requested</a>
                        @endif
                        @if($onOrder)
                            <span class="block mt-1.5 text-xs font-medium text-amber-600 whitespace-nowrap" title="Sold while out of stock. Restocking gives it its stock.">{{ $onOrder }} on order</span>
                        @elseif($stockIn)
                            <span class="block mt-1.5 text-xs font-medium text-emerald-600 whitespace-nowrap" title="Was on order. The stock came in: it can be made now.">Stock in</span>
                        @endif
                    </td>
                    <td class="px-2 text-slate-500 whitespace-nowrap">
                        {{ $sale->created_at->format('M j, Y') }}
                        <span class="block text-xs text-slate-400">{{ $sale->created_at->format('h:i A') }}</span>
                    </td>
                    <td class="pr-4">
                        {{-- Edit and Archive are on every row, so they stay at the right edge; the status actions come before them --}}
                        <div class="flex items-center justify-end gap-1.5">
                            @if($onOrder)
                            {{-- Stock that came in some other way than a restock (a count): the items take it --}}
                            <form method="POST" action="{{ route('sales.take-stock', $sale) }}">
                                @csrf
                                <button title="Take from stock: the items on order take their stock, if it has come in" aria-label="Take from stock"
                                    class="{{ $iconBtn }} bg-amber-50 text-amber-700 hover:bg-amber-100">
                                    <i data-lucide="package-check" class="w-4 h-4"></i>
                                </button>
                            </form>
                            @elseif($sale->status === 'processing')
                            <form method="POST" action="{{ route('sales.complete', $sale) }}">
                                @csrf
                                <button title="Mark completed" aria-label="Mark completed"
                                    class="{{ $iconBtn }} bg-emerald-50 text-emerald-700 hover:bg-emerald-100">
                                    <i data-lucide="check" class="w-4 h-4"></i>
                                </button>
                            </form>
                            @endif
                            @if(in_array($sale->status, ['pending', 'processing']))
                                @if($isOwner)
                                <form method="POST" action="{{ route('sales.void', $sale) }}" onsubmit="return confirm('Void this sale and restore stock?')">
                                    @csrf
                                    <button title="Void" aria-label="Void"
                                        class="{{ $iconBtn }} bg-red-50 text-red-600 hover:bg-red-100">
                                        <i data-lucide="ban" class="w-4 h-4"></i>
                                    </button>
                                </form>
                                @elseif(isset($voidRequested[$sale->id]))
                                {{-- Already asked: waiting for an owner --}}
                                <span title="Waiting for the owner to approve voiding it" aria-label="Void requested"
                                    class="{{ $iconBtn }} bg-slate-100 text-slate-400 cursor-default">
                                    <i data-lucide="hourglass" class="w-4 h-4"></i>
                                </span>
                                @else
                                {{-- A cashier asks an owner to void it, saying why --}}
                                <button type="button" title="Ask the owner to void it" aria-label="Ask to void"
                                    @click="askingVoid = { invoice: {{ json_encode($sale->invoice_number) }}, url: {{ json_encode(route('sales.void', $sale)) }} }; $nextTick(() => $refs.voidReason.focus())"
                                    class="{{ $iconBtn }} bg-red-50 text-red-600 hover:bg-red-100">
                                    <i data-lucide="ban" class="w-4 h-4"></i>
                                </button>
                                @endif
                            @endif
                            <button type="button" title="Edit" aria-label="Edit"
                                onclick='openSaleEditor(@json($saleEditPayload))'
                                class="{{ $iconBtn }} bg-brand-50 text-brand-700 hover:bg-brand-100">
                                <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>
                            @if($isOwner)
                            <button type="button" title="Archive this sale" aria-label="Archive this sale"
                                @click="confirmingArchiveSale = { id: {{ $sale->id }}, invoice: {{ json_encode($sale->invoice_number) }} }"
                                class="{{ $iconBtn }} border border-slate-200 text-slate-500 hover:bg-slate-50">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="p-8 text-center text-sm text-slate-400">
                    @if(request()->collect()->only(['search', 'date_from', 'date_to', 'status'])->filter()->isNotEmpty())
                        No sales match these filters.
                    @else
                        No sales recorded yet — ring one up through Billing/POS.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $sales->links() }}</div>
    </div>

    {{-- EDIT SALE MODAL --}}
    <div x-show="editingSale !== null" style="display:none;" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="editingSale = null">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-sm p-6" x-show="editingSale !== null" x-transition.scale>
            <div class="flex items-center justify-between mb-4">
                <p class="font-semibold text-ink flex items-center gap-2"><i data-lucide="pencil" class="w-4 h-4"></i> Edit Sale <span x-text="editingSale?.invoice_number"></span></p>
                <button @click="editingSale = null" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            {{-- Built fresh for each sale opened: nothing typed for one sale (a payment) carries over to
                 the next, and nothing reads a sale before one is open (that threw on every page load). --}}
            <template x-if="editingSale">
            <form :action="editingSale.update_url" method="POST" class="space-y-3">
                @csrf
                @method('PATCH')
                <div>
                    <label class="text-xs font-medium text-slate-600">Status</label>
                    <select name="status" x-model="editingSale.status" class="mt-1 w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="pending">Pending (down payment)</option>
                        <option value="processing">Processing (paid, in progress)</option>
                        <option value="completed">Completed</option>
                        {{-- A cashier asks an owner with the Void button instead --}}
                        @if($isOwner)
                        <option value="voided">Voided</option>
                        @endif
                    </select>
                </div>
                <div x-show="editingSale.balance > 0">
                    <label class="text-xs font-medium text-slate-600">
                        Record additional payment <span class="text-slate-400">(balance: ₱<span x-text="Number(editingSale.balance).toFixed(2)"></span>)</span>
                    </label>
                    <input type="number" name="additional_payment" step="0.01" min="0" placeholder="0.00"
                           class="mt-1 w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="editingSale = null" class="text-sm text-slate-500 px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-brand-600 text-white text-sm rounded-lg px-5 py-2">Save Changes</button>
                </div>
            </form>
            </template>
        </div>
    </div>

    {{-- A cashier asks an owner to void a sale: nothing is voided until it's approved --}}
    <div x-show="askingVoid !== null" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="askingVoid = null" @keydown.escape.window="askingVoid = null">
        <form x-show="askingVoid !== null" method="POST" :action="askingVoid ? askingVoid.url : ''"
              class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-md p-6">
            @csrf
            <p class="text-lg font-semibold text-ink mb-2">Ask the owner to void <span x-text="askingVoid?.invoice"></span>?</p>
            <p class="text-sm text-slate-500 mb-4">
                Voiding needs an owner's approval. The sale stays as it is until they approve it; then its stock comes back.
                You'll see the answer in My Requests.
            </p>
            <label for="void-reason" class="block text-xs font-medium text-slate-600 mb-1.5">Why should it be voided?</label>
            <textarea id="void-reason" x-ref="voidReason" name="reason" rows="2" required maxlength="500" placeholder="e.g. the customer cancelled the order"
                      class="w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500"></textarea>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 mt-5">
                <button type="button" @click="askingVoid = null" class="border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2">Cancel</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">Ask the owner</button>
            </div>
        </form>
    </div>

    {{-- SHADCN-STYLE ALERT DIALOG — Archive confirmation --}}
    <div x-show="confirmingArchiveSale !== null" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingArchiveSale = null">
        <div x-show="confirmingArchiveSale !== null" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-md p-6">
            <p class="text-lg font-semibold text-ink mb-2">Archive this sale?</p>
            <p class="text-sm text-slate-500 mb-6">
                <span x-text="confirmingArchiveSale?.invoice"></span> will be moved to the Archive, not permanently deleted — you can restore it from there any time.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="confirmingArchiveSale = null"
                    class="border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2">
                    Cancel
                </button>
                <button @click="document.getElementById('archive-sale-form-' + confirmingArchiveSale.id).submit()"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">
                    Archive
                </button>
            </div>
        </div>
    </div>

    @if($isOwner)
    @foreach($sales as $sale)
        <form id="archive-sale-form-{{ $sale->id }}" method="POST" action="{{ route('sales.archiveOne', $sale) }}" class="hidden">
            @csrf
        </form>
    @endforeach
    @endif
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
