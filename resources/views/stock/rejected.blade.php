@extends('layouts.app')
@section('title', 'Scrapped / Rejected Output')

@section('content')
@php
    $fmt = fn ($n) => \App\Services\Inventory\StockAlertService::formatQty($n);
    $input = 'mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    $label = 'text-xs font-medium text-slate-600 dark:text-slate-300';
    $btnCancel = 'border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2';
    $btnPrimary = 'bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-md px-4 py-2';
    $th = 'text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700';
    $statusStyle = [
        'rejected' => ['Rejected', 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'],
        'scrap_sold' => ['Sold as scrap', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'],
        'discarded' => ['Discarded', 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300'],
    ];
    $rejectData = $rejects->mapWithKeys(fn ($r) => [$r->id => [
        'id' => $r->id,
        'ref' => $r->reference,
        'item' => $r->item_name,
        'cost' => (float) $r->material_cost,
        'urls' => ['scrap' => route('stock.rejected.scrap', $r), 'discard' => route('stock.rejected.discard', $r)],
    ]])->all();
@endphp

@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
        {{ $errors->first() }}
    </div>
@endif

<div x-data="rejectPage()">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
        <p class="text-sm text-slate-500 dark:text-slate-400 max-w-2xl">
            Production runs that came out wrong because of a machine or process error. The materials they used are
            logged as waste. If it was a customer's job that you reprinted, enter their invoice: the sale still counts,
            and the lost material comes off its profit. If the rejects are still sold, the money goes into Sales as its
            own invoice, but it yields zero profit because the lost material cost cancels it out.
        </p>
        <div class="flex flex-wrap items-end gap-2">
            <form method="GET" action="{{ route('stock.rejected') }}" class="flex items-end gap-2">
                <div>
                    <label for="rej-month" class="{{ $label }}">Month</label>
                    <input id="rej-month" type="month" name="month" value="{{ $start->format('Y-m') }}" class="mt-1 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 block">
                </div>
                <button type="submit" class="text-sm border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg px-4 py-2">Show</button>
            </form>
            <button type="button" @click="show('record')"
                class="text-sm px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Record rejected output
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Rejected, {{ $start->format('F Y') }}</p>
            <p class="text-2xl font-semibold mt-1 {{ $totals['records'] ? 'text-red-600' : 'text-ink dark:text-white' }}">{{ $totals['units'] }}</p>
            <p class="text-xs text-slate-400">{{ $totals['records'] }} {{ $totals['records'] === 1 ? 'record' : 'records' }}</p>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Material lost</p>
            <p class="text-2xl font-semibold mt-1 {{ $totals['cost'] > 0 ? 'text-red-600' : 'text-ink dark:text-white' }}">₱{{ number_format($totals['cost'], 2) }}</p>
            @if($totals['reprint_cost'] > 0)
                <p class="text-xs text-slate-400">₱{{ number_format($totals['reprint_cost'], 2) }} of it off reprinted jobs' profit</p>
            @endif
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Scrap sales</p>
            <p class="text-2xl font-semibold text-ink dark:text-white mt-1">₱{{ number_format($totals['scrap'], 2) }}</p>
            <p class="text-xs text-slate-400">Counted in Sales</p>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Profit from scrap</p>
            <p class="text-2xl font-semibold text-ink dark:text-white mt-1">₱0.00</p>
            <p class="text-xs text-slate-400">The lost material cancels it out</p>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="{{ $th }}">
                <th class="px-4 py-3">Ref</th><th class="px-3 py-3">Item</th><th class="px-3 py-3">What went wrong</th>
                <th class="px-3 py-3">Materials lost</th><th class="px-3 py-3 text-right">Material cost</th>
                <th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right">Profit</th><th class="px-3 py-3 pr-4"></th>
            </tr></thead>
            <tbody>
            @forelse($rejects as $r)
                @php
                    [$stText, $stClass] = $statusStyle[$r->status];
                    $lost = $r->losses->groupBy(fn ($a) => $a->batch->material_id)->map(function ($rows) use ($fmt) {
                        $mat = $rows->first()->batch->material;
                        return e($fmt(-1 * $rows->sum('quantity')) . ' ' . ($mat->unit ?? '') . ' ' . ($mat->name ?? ''))
                            . ' <span class="whitespace-nowrap">(' . e($rows->pluck('batch.batch_number')->unique()->implode(', ')) . ')</span>';
                    });
                @endphp
                <tr class="border-b border-slate-50 dark:border-slate-700" style="vertical-align:top;">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <p class="font-medium text-ink dark:text-white">{{ $r->reference }}</p>
                        <p class="text-xs text-slate-400">{{ $r->created_at->format('M j, h:i A') }}</p>
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-ink dark:text-white">{{ $r->item_name }}</p>
                        <p class="text-xs text-slate-400">{{ $r->quantity }} rejected</p>
                        @if($r->sale)
                            {{-- Reprinted for this customer: their sale still counts, at less profit --}}
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Reprinted for <span class="whitespace-nowrap">{{ $r->sale->invoice_number }}</span>:
                                ₱{{ number_format($r->material_cost, 2) }} off its profit
                            </p>
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-ink dark:text-white">{{ $r->cause_label }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $r->reason }}</p>
                    </td>
                    <td class="px-3 py-3 text-xs text-slate-500 dark:text-slate-400">{!! $lost->implode('<br>') ?: '-' !!}</td>
                    <td class="px-3 py-3 text-right whitespace-nowrap text-red-600">₱{{ number_format($r->material_cost, 2) }}</td>
                    <td class="px-3 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs whitespace-nowrap {{ $stClass }}">{{ $stText }}</span>
                        @if($r->status === 'scrap_sold')
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 whitespace-nowrap">
                                ₱{{ number_format($r->scrap_revenue, 2) }}{{ $r->scrapSale ? ' in Sales, ' . $r->scrapSale->invoice_number : ' scrap revenue' }}
                            </p>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-right whitespace-nowrap">₱0.00</td>
                    <td class="px-3 py-3 pr-4 whitespace-nowrap text-right">
                        @if($r->status === 'rejected')
                            <button type="button" @click="show('scrap', {{ $r->id }})" class="text-xs text-brand-600 hover:underline">Sold as scrap</button>
                            <button type="button" @click="show('discard', {{ $r->id }})" class="text-xs text-slate-500 hover:underline ml-2">Discard</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-8 text-center text-sm text-slate-400">No rejected output in {{ $start->format('F Y') }}.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div x-show="modal !== null" style="display:none;" x-transition.opacity
         class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
         @click.self="hide()" @keydown.escape.window="hide()">
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full p-6 my-8" style="max-width:34rem;">

            {{-- Record a failed production run --}}
            <form x-show="modal === 'record'" style="display:none;" method="POST" action="{{ route('stock.rejected.store') }}" class="space-y-3">
                @csrf
                <div>
                    <p class="text-lg font-semibold text-ink dark:text-white">Record rejected output</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Its materials come out of their active batches as waste.</p>
                </div>
                <div class="flex gap-3">
                    <div class="flex-1 min-w-0">
                        <label class="{{ $label }}">Product size (optional)</label>
                        <select name="product_variant_id" x-model="variant" @change="fill()" class="{{ $input }}">
                            <option value="">Not a product</option>
                            @foreach($recipes as $v)
                                <option value="{{ $v['id'] }}">{{ $v['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="width:7rem;">
                        <label class="{{ $label }}">How many</label>
                        <input type="number" min="1" name="quantity" x-model="qty" @input="fill(); lines.forEach(l => resize(l))" required class="{{ $input }}">
                    </div>
                </div>
                <div>
                    <label class="{{ $label }}">What was being made</label>
                    <input type="text" name="item_name" x-model="itemName" required maxlength="150" placeholder="e.g. PVC ID" class="{{ $input }}">
                </div>
                <div class="flex gap-3">
                    <div style="width:9rem;">
                        <label class="{{ $label }}">Cause</label>
                        <select name="cause" class="{{ $input }}">
                            <option value="machine">Machine error</option>
                            <option value="process">Process error</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="flex-1 min-w-0">
                        <label class="{{ $label }}">What went wrong</label>
                        <input type="text" name="reason" required maxlength="255" placeholder="e.g. printer jammed, colors off" class="{{ $input }}">
                    </div>
                </div>
                <div>
                    <p class="{{ $label }}">Materials used up</p>
                    <template x-for="(line, i) in lines" :key="i">
                        <div class="mt-1">
                            <div class="flex gap-2">
                                <select :name="'lines[' + i + '][material_id]'" x-model="line.material_id" @change="resize(line)"
                                    class="flex-1 min-w-0 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                                    <option value="">Pick a material</option>
                                    @foreach($materials as $mat)
                                        <option value="{{ $mat->id }}">{{ $mat->name }}{{ $mat->isSheet() ? ' (sheet, sq ft)' : ($mat->inventory_type === 'continuous' ? ' (continuous)' : '') }}</option>
                                    @endforeach
                                </select>
                                <div class="flex gap-1" style="width:9rem;">
                                    <input type="number" step="0.001" min="0" :name="'lines[' + i + '][quantity]'" x-model="line.quantity"
                                        :placeholder="unit(line.material_id) || 'qty'"
                                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                                    <button type="button" @click="removeLine(i)" class="text-lg text-slate-400 hover:bg-red-50 rounded-lg px-2" aria-label="Remove line">&times;</button>
                                </div>
                            </div>
                            {{-- Sheet materials (sintra board): the size of one spoiled piece works out the sq ft --}}
                            <template x-if="sheetOf(line.material_id)">
                                <div class="flex flex-wrap items-center gap-1 mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    <span>Size of one piece</span>
                                    <input type="number" step="0.01" min="0" x-model="line.w" @input="resize(line)" aria-label="Width"
                                        class="text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-2 py-1" style="width:4.5rem;">
                                    <span>x</span>
                                    <input type="number" step="0.01" min="0" x-model="line.h" @input="resize(line)" aria-label="Height"
                                        class="text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-2 py-1" style="width:4.5rem;">
                                    <select x-model="line.unit" @change="resize(line)" aria-label="Unit"
                                        class="text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-2 py-1">
                                        <option value="ft">ft</option>
                                        <option value="in">in</option>
                                    </select>
                                    <span x-show="line.w && line.h">x <span x-text="qty || 1"></span> pcs = <span x-text="line.quantity"></span> sq ft</span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <button type="button" @click="addLine()" class="text-xs text-brand-600 mt-2">+ Add a material</button>
                    <p class="text-xs text-slate-400 mt-1">Continuous materials (fabric, ink) can't be calculated: type how much was used. For sheet materials, enter the size of one spoiled piece.</p>
                </div>
                <div>
                    <label class="{{ $label }}">Reprinted for invoice (optional)</label>
                    <input type="text" name="invoice" maxlength="30" placeholder="INV-..." class="{{ $input }}">
                    <p class="text-xs text-slate-400 mt-1">If it was a customer's job: their sale still counts, and this lost material comes off its profit.</p>
                </div>
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 space-y-2">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" x-model="soldNow" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm text-ink dark:text-white">It was still sold</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">The money goes into Sales as its own invoice and into your cash count. It counts as ₱0.00 profit.</span>
                        </span>
                    </label>
                    <template x-if="soldNow">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="{{ $label }}">Sold for (₱)</label>
                                <input type="number" step="0.01" min="0.01" name="scrap_revenue" required class="{{ $input }}">
                            </div>
                            <div>
                                <label class="{{ $label }}">Paid by</label>
                                <select name="payment_method" class="{{ $input }}">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Record as rejected</button>
                </div>
            </form>

            {{-- Sold as scrap --}}
            <form x-show="modal === 'scrap'" style="display:none;" :action="r ? r.urls.scrap : ''" method="POST" class="space-y-3">
                @csrf
                <div>
                    <p class="text-lg font-semibold text-ink dark:text-white">Sold as scrap</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        <span x-text="r ? r.ref + ', ' + r.item : ''"></span>. The money goes into Sales as its own invoice and into your cash count. It counts as ₱0.00 profit.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $label }}">Sold for (₱)</label>
                        <input type="number" step="0.01" min="0.01" name="scrap_revenue" required class="{{ $input }}">
                    </div>
                    <div>
                        <label class="{{ $label }}">Paid by</label>
                        <select name="payment_method" class="{{ $input }}">
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="{{ $label }}">Note (optional)</label>
                    <input type="text" name="scrap_note" maxlength="255" placeholder="e.g. sold as clearance" class="{{ $input }}">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Save</button>
                </div>
            </form>

            {{-- Discard --}}
            <form x-show="modal === 'discard'" style="display:none;" :action="r ? r.urls.discard : ''" method="POST" class="space-y-3">
                @csrf
                <p class="text-lg font-semibold text-ink dark:text-white">Discard these rejects?</p>
                <p class="text-sm text-slate-500 dark:text-slate-400"><span x-text="r ? r.ref + ', ' + r.item : ''"></span> was thrown away. Nothing is sold.</p>
                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Keep it</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">Discard</button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
const rejectData = @js($rejectData);
const recipes = @js($recipes);
const materialList = @js($materials->map(fn ($m) => ['id' => $m->id, 'unit' => $m->unit, 'sheet' => $m->isSheet()])->values());
// "Mistake" on a sheet material's card opens the form with that material filled in.
const recordFor = @js($recordFor);

// One material line. w, h and unit are the size helper for sheet materials; only quantity is sent.
function newLine(materialId = '') {
    return { material_id: String(materialId), quantity: '', w: '', h: '', unit: 'ft' };
}

function rejectPage() {
    return {
        modal: null,
        r: null,
        variant: '',
        qty: 1,
        itemName: '',
        soldNow: false,
        lines: [newLine()],

        init() {
            if (!recordFor) return;
            this.show('record');
            this.lines = [newLine(recordFor)];
            // Saving comes back to this page. Without this, the form would open again.
            const url = new URL(window.location.href);
            url.searchParams.delete('record');
            window.history.replaceState(null, '', url);
        },

        show(modal, id = null) {
            this.r = id ? rejectData[id] : null;
            this.modal = modal;
            if (modal === 'record') {
                this.variant = '';
                this.qty = 1;
                this.itemName = '';
                this.soldNow = false;
                this.lines = [newLine()];
            }
        },

        hide() { this.modal = null; },

        // Fill the materials from the product size's recipe. Discrete materials are
        // worked out per unit; continuous ones can't be, so they are left for staff to type.
        fill() {
            const v = recipes.find(x => String(x.id) === String(this.variant));
            if (!v) return;
            const n = parseInt(this.qty) || 1;
            this.itemName = v.label;
            this.lines = v.lines.map(l => ({
                ...newLine(l.material_id),
                quantity: (l.fixed && !l.continuous) ? Math.round(l.per_unit * n * 1000) / 1000 : '',
            }));
            if (!this.lines.length) this.lines = [newLine()];
        },

        addLine() { this.lines.push(newLine()); },

        removeLine(i) {
            this.lines.splice(i, 1);
            if (!this.lines.length) this.addLine();
        },

        unit(id) {
            const m = materialList.find(x => String(x.id) === String(id));
            return m ? m.unit : '';
        },

        sheetOf(id) {
            const m = materialList.find(x => String(x.id) === String(id));
            return !!(m && m.sheet);
        },

        // Sheet materials: sq ft lost = one piece's size x how many came out rejected.
        resize(line) {
            if (!this.sheetOf(line.material_id)) return;
            const perFoot = line.unit === 'in' ? 12 : 1;
            const area = (parseFloat(line.w) / perFoot) * (parseFloat(line.h) / perFoot) * (parseInt(this.qty) || 1);
            if (area > 0) line.quantity = Math.round(area * 1000) / 1000;
        },
    };
}
</script>
@endsection
