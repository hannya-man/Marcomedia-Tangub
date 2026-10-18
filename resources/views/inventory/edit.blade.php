@extends('layouts.app')
@section('title', 'Edit Product')
@section('shows-errors', '1')

@section('content')
@php
    $label = 'block mb-1.5 text-xs font-medium text-slate-600';
    $input = 'w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500/25 focus:border-brand-500';
    $back = session('inventory.list_url', route('inventory.index'));
    // Row icons are drawn inline: Lucide only converts icons there when the page loads, not ones Alpine adds.
    $svg = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
@endphp

<a href="{{ $back }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg px-4 py-2 mb-4 shadow-sm">
    <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Inventory
</a>

@php $isOwner = auth()->user()->role === 'admin'; @endphp
@unless($isOwner)
    {{-- A cashier's changes go to an owner for approval (InventoryController::update) --}}
    <div class="mb-4 rounded-lg bg-brand-50 border border-brand-200 text-brand-800 px-4 py-3 text-sm flex gap-2">
        <i data-lucide="info" class="w-4 h-4 flex-shrink-0 mt-0.5"></i>
        <div>
            <p>Your changes go to the owner for approval. Nothing changes until it's approved: you'll see the answer in My Requests.</p>
            @if($waiting)
                <p class="mt-1 font-medium">You already sent changes to this product {{ $waiting->created_at->diffForHumans() }}, still waiting. Sending again replaces them.</p>
            @endif
        </div>
    </div>
@endunless

@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
        <p class="font-medium">Nothing was {{ $isOwner ? 'saved' : 'sent' }} yet:</p>
        <ul class="list-disc pl-5 mt-1 space-y-0.5">
            @foreach(collect($errors->all())->unique() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('inventory.update', $product) }}" x-data="productEditor()">
    @csrf
    @method('PUT')

    {{-- ============ The product ============ --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5 sm:p-6 mb-6">
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-lg bg-brand-50 flex items-center justify-center flex-shrink-0">
                <i data-lucide="package" class="w-5 h-5 text-brand-600"></i>
            </div>
            <div>
                <p class="font-medium text-ink">Product</p>
                <p class="text-xs text-slate-500">What the cashier sees at Billing/POS. Past sales keep the names and prices they were sold at.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="name" class="{{ $label }}">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $product->name) }}" required maxlength="150" class="{{ $input }}">
            </div>
            <div>
                <label for="sku" class="{{ $label }}">SKU</label>
                <input id="sku" type="text" name="sku" x-model="productSku" required maxlength="60" class="{{ $input }}">
            </div>
            <div>
                <span class="{{ $label }}">Category</span>
                <x-select-menu name="category_id"
                    :options="collect(['' => 'No category'])->union($categories->mapWithKeys(fn ($c) => [$c->id => $c->name . ($c->archived_at ? ' (archived)' : '')]))->all()"
                    :selected="old('category_id', $product->category_id)"
                    placeholder="No category" />
            </div>
            <div>
                <label for="base_price" class="{{ $label }}">Base price (₱)</label>
                <input id="base_price" type="number" name="base_price" x-model="basePrice" step="0.01" min="0" required class="{{ $input }}">
                <p class="text-[11px] text-slate-500 mt-1">The price of every size that has none of its own. ₱0: the cashier enters it at the counter.</p>
            </div>
            <div class="md:col-span-2">
                <label for="description" class="{{ $label }}">Description <span class="text-slate-400 font-normal">(optional)</span></label>
                <textarea id="description" name="description" rows="2" maxlength="2000" class="{{ $input }}">{{ old('description', $product->description) }}</textarea>
            </div>
            <label class="md:col-span-2 flex items-start gap-3 rounded-lg border border-slate-200 p-3 cursor-pointer">
                <input type="checkbox" name="track_inventory" value="1" x-model="track" class="mt-0.5 w-4 h-4 accent-brand-600">
                <span>
                    <span class="block text-sm font-medium text-ink">Keep a stock count</span>
                    <span class="block text-xs text-slate-500 mt-0.5">On for items made ahead and kept on a shelf: a sale takes from the count. Off for made to order: a sale takes the materials each size is made from.</span>
                </span>
            </label>
        </div>
    </div>

    {{-- ============ Sizes and prices ============ --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        <div class="p-5 sm:p-6 border-b border-slate-100">
            <p class="font-medium text-ink">Sizes and prices</p>
            <p class="text-xs text-slate-500 mt-1">
                A size's price is what Billing/POS charges for it. Leave it blank to use the base price. Untick
                <span class="font-medium">Sold</span> to stop offering a size without losing it.
            </p>
            @if($cutFrom->isNotEmpty())
                <p class="text-xs text-slate-500 mt-2 flex items-start gap-1.5">
                    <i data-lucide="scissors" class="w-3.5 h-3.5 mt-px flex-shrink-0"></i>
                    @if($cutFrom->every(fn ($m) => $m->isRoll()))
                        <span>Printed on {{ $cutFrom->pluck('name')->implode(', ') }}. A size that isn't listed is sold as Custom size, priced at the counter.</span>
                    @else
                        <span>Cut from {{ $cutFrom->pluck('name')->implode(', ') }}. New sizes are added on the board's card in Discrete Materials (Add a size); here you set their names and prices.</span>
                    @endif
                </p>
            @endif
        </div>

        {{-- Column names, on wide screens --}}
        <div class="hidden md:grid grid-cols-12 gap-2 px-6 py-2 bg-slate-50 text-[11px] font-medium uppercase tracking-wide text-slate-500">
            <span class="col-span-4">Size</span>
            <span class="col-span-3">SKU</span>
            <span class="col-span-2">Price (₱)</span>
            <span class="col-span-1" x-show="track">Low at</span>
            <span :class="track ? 'col-span-1' : 'col-span-2'">Sold</span>
            <span class="col-span-1"></span>
        </div>

        <template x-for="(row, i) in rows" :key="row.uid">
            <div class="px-5 sm:px-6 py-3 border-t border-slate-100" :class="row.remove ? 'bg-red-50/60' : ''">
                <input type="hidden" :name="`variants[${i}][id]`" :value="row.id ?? ''">
                <input type="hidden" :name="`variants[${i}][active]`" :value="row.active ? 1 : 0">
                <input type="hidden" :name="`variants[${i}][remove]`" :value="row.remove ? 1 : 0">

                <div class="grid grid-cols-12 gap-2 items-center" :class="row.remove ? 'opacity-50' : ''">
                    <div class="col-span-12 md:col-span-4">
                        <input type="text" x-model="row.variant_name" @input="suggestSku(row)" :name="`variants[${i}][variant_name]`" data-size-name
                            required maxlength="100" placeholder="e.g. 12 x 18 in" aria-label="Size name" class="{{ $input }}">
                    </div>
                    <div class="col-span-6 md:col-span-3">
                        <input type="text" x-model="row.sku" @input="row.skuTouched = true" :name="`variants[${i}][sku]`"
                            required maxlength="60" placeholder="SKU" aria-label="Size SKU" class="{{ $input }} font-mono text-xs">
                    </div>
                    <div class="col-span-6 md:col-span-2 relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">₱</span>
                        <input type="number" step="0.01" min="0" x-model="row.price" :name="`variants[${i}][price]`"
                            :placeholder="pricePlaceholder()" aria-label="Price" class="{{ $input }} pl-7 text-right">
                    </div>
                    <div class="col-span-4 md:col-span-1" x-show="track">
                        <input type="number" min="0" step="1" x-model="row.low_stock_threshold" :name="`variants[${i}][low_stock_threshold]`"
                            aria-label="Low stock at" title="Warn when this many are left" class="{{ $input }} text-right">
                    </div>
                    <label class="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer" :class="track ? 'col-span-4 md:col-span-1' : 'col-span-6 md:col-span-2'">
                        <input type="checkbox" x-model="row.active" :disabled="row.remove" class="w-4 h-4 accent-brand-600"> <span class="md:hidden">Sold</span>
                    </label>
                    <div class="flex justify-end" :class="track ? 'col-span-4 md:col-span-1' : 'col-span-6 md:col-span-1'">
                        <button type="button" @click="removeRow(i)" :title="row.remove ? 'Keep this size' : 'Remove this size'" :aria-label="row.remove ? 'Keep this size' : 'Remove this size'"
                            class="w-9 h-9 rounded-lg border flex items-center justify-center"
                            :class="row.remove ? 'border-slate-300 text-slate-600 hover:bg-slate-50' : 'border-red-200 text-red-500 hover:bg-red-50'">
                            <svg x-show="!row.remove" {!! $svg !!} class="w-4 h-4"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            <svg x-show="row.remove" {!! $svg !!} class="w-4 h-4"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                        </button>
                    </div>
                </div>

                <p x-show="row.remove" class="mt-2 text-xs text-red-600">Removed when you save. A size that was ever sold is hidden instead, to keep its sales.</p>

                {{-- What one of this size is made from (a fixed amount of each). Sizes cut from a board
                     or roll take it by size; that is set on the board's card. --}}
                <template x-if="!row.remove && !isCut(row)">
                <div class="mt-2 rounded-lg bg-slate-50 px-3 py-2">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-slate-500 mb-1.5">Made from <span class="normal-case font-normal tracking-normal">(for each one sold)</span></p>
                    <div class="space-y-1.5">
                        <template x-for="(line, j) in row.materials" :key="j">
                            <div class="flex items-center gap-2">
                                <select x-model="line.material_id" :name="`variants[${i}][materials][${j}][material_id]`" aria-label="Material"
                                    class="flex-1 min-w-0 text-xs rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                                    <option value="">Pick a material</option>
                                    @foreach($materials as $m)
                                        <option value="{{ $m->id }}">{{ $m->name }}{{ $m->archived_at ? ' (archived)' : '' }}</option>
                                    @endforeach
                                </select>
                                {{-- A counted (discrete) material takes whole pieces --}}
                                <input type="number" :step="counted[line.material_id] ? 1 : 0.001" :min="counted[line.material_id] ? 1 : 0" x-model="line.quantity_per_unit" :name="`variants[${i}][materials][${j}][quantity_per_unit]`"
                                    :placeholder="counted[line.material_id] ? 'How many' : 'Qty'" aria-label="Amount for one" class="w-20 text-xs text-right rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                                <span class="w-14 text-xs text-slate-500 truncate" x-text="units[line.material_id] || ''"></span>
                                <button type="button" @click="row.materials.splice(j, 1)" title="Remove this material" aria-label="Remove this material"
                                    class="w-7 h-7 flex-shrink-0 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50 flex items-center justify-center">
                                    <svg {!! $svg !!} class="w-3.5 h-3.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                </button>
                            </div>
                        </template>
                    </div>
                    <p x-show="row.materials.length === 0" class="text-xs text-slate-400">Nothing listed: a sale takes no materials.</p>
                    <button type="button" @click="row.materials.push({ material_id: '', quantity_per_unit: 1 })"
                        class="mt-1.5 text-xs font-medium text-brand-600 hover:underline inline-flex items-center gap-1">
                        <svg {!! $svg !!} class="w-3 h-3"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add a material
                    </button>
                </div>
                </template>
            </div>
        </template>

        <div x-show="rows.length === 0" class="px-6 py-6 text-sm text-slate-500 border-t border-slate-100">
            No sizes: it's sold as one item at the base price.
        </div>

        @if($cutFrom->isEmpty())
            <div class="px-5 sm:px-6 py-3 border-t border-slate-100">
                <button type="button" @click="addRow()" class="text-sm font-medium text-brand-600 hover:underline inline-flex items-center gap-1.5">
                    <svg {!! $svg !!} class="w-4 h-4"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add a size
                </button>
            </div>
        @endif
    </div>

    {{-- Stays in reach on a long list of sizes --}}
    <div class="sticky bottom-0 z-20 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-white/95 backdrop-blur border-t border-slate-200 flex items-center justify-end gap-2">
        <a href="{{ $back }}" class="text-sm font-medium text-slate-700 border border-slate-300 hover:bg-slate-50 rounded-lg px-4 py-2">Cancel</a>
        <button type="submit" class="text-sm font-medium bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-5 py-2">{{ $isOwner ? 'Save changes' : 'Send for approval' }}</button>
    </div>
</form>

<script>
// The sizes as the form shows them, and what the rows need to look things up.
const productEditorData = @js([
    'rows' => $rows,
    'cutIds' => $cutIds,
    'units' => $materials->mapWithKeys(fn ($m) => [(string) $m->id => $m->unit]),
    'counted' => $materials->mapWithKeys(fn ($m) => [(string) $m->id => $m->inventory_type !== 'continuous']),
    'sku' => old('sku', $product->sku),
    'basePrice' => old('base_price', (float) $product->base_price),
    'track' => (bool) old('track_inventory', $product->track_inventory),
]);

function productEditor() {
    let uid = 0;
    const d = productEditorData;
    return {
        productSku: d.sku,
        basePrice: d.basePrice,
        track: d.track,
        units: d.units,
        counted: d.counted,
        // A size already saved keeps its SKU as typed; a new one gets one made from its name until typed in.
        rows: d.rows.map(r => ({
            ...r, uid: ++uid, skuTouched: true,
            materials: (r.materials || []).map(m => ({ material_id: m.material_id == null ? '' : String(m.material_id), quantity_per_unit: m.quantity_per_unit ?? '' })),
        })),

        isCut(row) {
            return row.id !== null && d.cutIds.includes(row.id);
        },
        addRow() {
            this.rows.push({ uid: ++uid, id: null, variant_name: '', sku: '', skuTouched: false, price: '', low_stock_threshold: 5, active: true, remove: false, materials: [] });
            this.$nextTick(() => { const names = this.$root.querySelectorAll('[data-size-name]'); names[names.length - 1]?.focus(); });
        },
        // A saved size is marked to go (undo by pressing again); a new one just leaves the form.
        removeRow(i) {
            const row = this.rows[i];
            if (row.id) row.remove = !row.remove; else this.rows.splice(i, 1);
        },
        suggestSku(row) {
            if (row.skuTouched) return;
            row.sku = (this.productSku + '-' + row.variant_name).toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        },
        pricePlaceholder() {
            const base = parseFloat(this.basePrice);
            return base > 0 ? base.toFixed(2) : 'At counter';
        },
    };
}
</script>
@endsection
