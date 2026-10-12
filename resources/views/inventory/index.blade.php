@extends('layouts.app')
@section('title', 'Inventory')

@php
    // A refused Add Product comes back open, with what was typed and why it wasn't saved
    // (it used to close, and the product was simply missing).
    $addOld = old('_form') === 'add-product';
    $oldRows = collect($addOld ? old('variants', []) : [])->values()->map(fn ($row) => [
        'name' => $row['variant_name'] ?? '',
        'sku' => $row['sku'] ?? '',
        'price' => $row['price'] ?? '',
        'threshold' => $row['low_stock_threshold'] ?? 5,
        'materials' => collect($row['materials'] ?? [])->values()->map(fn ($line) => [
            'material_id' => (string) ($line['material_id'] ?? ''),
            'quantity_per_unit' => $line['quantity_per_unit'] ?? '',
        ])->all(),
    ])->all();
@endphp
@if($addOld)
    @section('shows-errors', '1')
@endif

@section('content')
<div x-data="{
        addingProduct: @js($addOld), hasVariants: @js($addOld && old('has_variants')),
        trackInventory: @js($addOld ? (bool) old('track_inventory') : true),
        addingCategory: @js($addOld && filled(old('new_category_name'))),
        name: @js($addOld ? (string) old('name') : ''), price: @js($addOld ? (string) old('base_price') : ''),
        rows: @js($oldRows), confirmingDelete: null,
        init() {
            // Sizes brought back after a refused save are drawn after the page's icons were.
            if (this.rows.length) this.$nextTick(() => lucide.createIcons({ root: this.$root }));
        },
        closeAddProduct() {
            const hasProgress = this.name.trim() !== '' || this.price !== '' || this.rows.length > 0;
            if (hasProgress && !confirm('Leaving now will not save the product you were adding. Are you sure you want to close this?')) {
                return;
            }
            this.addingProduct = false;
        }
     }">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <form method="GET" id="inventory-search-form" class="flex gap-2">
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" id="inventory-search-input" value="{{ request('search') }}" placeholder="Search product..." autocomplete="off"
                       class="text-sm rounded-full border border-slate-300 bg-white pl-10 pr-9 py-2.5 w-64 shadow-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <i id="inventory-search-spinner" data-lucide="loader-2" class="w-3.5 h-3.5 text-brand-500 absolute right-3.5 top-1/2 -translate-y-1/2 animate-spin" style="display:none;"></i>
            </div>
            <div class="w-48">
                <x-select-menu name="category_id"
                    :options="collect(['' => 'All categories'])->union($categories->pluck('name', 'id'))->all()"
                    :selected="request('category_id')"
                    placeholder="All categories"
                    onchange="applyProductFilters('push')" />
            </div>
        </form>
        <div class="flex gap-2">
            <a href="{{ route('inventory.categories') }}" class="text-sm px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5">
                <i data-lucide="tags" class="w-3.5 h-3.5"></i> Categories
            </a>
            <a href="{{ route('inventory.archive') }}" class="text-sm px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5">
                <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
            </a>
            <button @click="addingProduct = true" class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg px-4 py-2 flex items-center gap-1.5">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Product
            </button>
        </div>
    </div>

    {{-- Pages and filters in place, with a fade (smoothList in layouts/app): no full reload --}}
    <div id="product-list" data-smooth-list class="scroll-mt-6">
    <div class="space-y-4">
    @forelse($products as $product)
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="flex justify-between items-center px-5 py-4 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-brand-50 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="package" class="w-5 h-5 text-brand-600"></i>
                    </div>
                    <div>
                        <p class="font-medium text-ink">{{ $product->name }}</p>
                        <p class="text-xs text-slate-500">SKU: {{ $product->sku }} · {{ $product->category->name ?? 'Uncategorized' }}</p>
                    </div>
                </div>
                <div class="text-right">
                    @php $sizePrices = $product->variants->pluck('price')->filter(fn ($p) => $p > 0); @endphp
                    @if($product->base_price > 0)
                        <p class="text-sm font-semibold text-ink">₱{{ number_format($product->base_price,2) }}</p>
                        <p class="text-xs text-slate-400">base price</p>
                    @elseif($sizePrices->isNotEmpty())
                        {{-- Priced by size (its price list) --}}
                        <p class="text-sm font-semibold text-ink">From ₱{{ number_format($sizePrices->min(), 2) }}</p>
                        <p class="text-xs text-slate-400">price by size</p>
                    @else
                        {{-- No set price: the cashier enters it at Billing/POS --}}
                        <p class="text-sm font-medium text-amber-600">Price at counter</p>
                        <p class="text-xs text-slate-400">set on each sale</p>
                    @endif
                </div>
                <div class="ml-3 flex items-center gap-2">
                    {{-- The owner sets the product's details, its sizes and their prices --}}
                    @if(auth()->user()->role === 'admin')
                        <a href="{{ route('inventory.edit', $product) }}" title="Edit product and prices" aria-label="Edit {{ $product->name }}"
                           class="w-8 h-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 hover:text-brand-700 flex items-center justify-center">
                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                        </a>
                    @endif
                    <form method="POST" action="{{ route('inventory.destroy', $product) }}" id="delete-product-form-{{ $product->id }}">
                        @csrf @method('DELETE')
                        <button type="button" title="Delete product" aria-label="Delete {{ $product->name }}"
                            @click="confirmingDelete = { id: {{ $product->id }}, name: {{ json_encode($product->name) }} }"
                            class="w-8 h-8 rounded-lg border border-red-200 text-red-500 hover:bg-red-50 flex items-center justify-center">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            </div>

            @if($product->has_variants)
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 bg-slate-50">
                        <th class="px-5 py-2.5 font-medium">Size / Variant</th>
                        <th class="px-5 py-2.5 font-medium">SKU</th>
                        <th class="px-5 py-2.5 font-medium text-right">Price</th>
                        <th class="px-5 py-2.5 font-medium text-right">Stock</th>
                        <th class="px-5 py-2.5 font-medium text-right">Low Stock At</th>
                        <th class="px-5 py-2.5 font-medium">Status</th>
                        <th class="px-5 py-2.5 font-medium">Restock</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($product->variants as $variant)
                    @php
                        // Its materials: what each takes (shown on hover), and the ones running low or out,
                        // worst first, for one short pill in Status. Out of stock still sells, on order.
                        $statuses = $variant->materials->mapWithKeys(fn ($m) => [$m->id => $m->stock_status]);
                        $recipe = $variant->materials->map(function ($m) use ($statuses) {
                            $how = $m->isSheet() ? 'cut into standard pieces'
                                : (($m->pivot->consumption_type ?? 'fixed') === 'per_area' ? 'cut by size'
                                : rtrim(rtrim(number_format($m->pivot->quantity_per_unit, 3), '0'), '.') . " {$m->unit} per unit");
                            $hand = $m->inventory_type === 'continuous' && ! $m->isSheet() ? ', logged by hand' : '';
                            $state = ['out_of_stock' => ' (out of stock: sold on order)', 'low_stock' => ' (running low)'][$statuses[$m->id]] ?? '';
                            return "{$m->name}: {$how}{$hand}{$state}";
                        })->implode("\n") ?: 'No materials listed';
                        $short = $variant->materials->reject(fn ($m) => $statuses[$m->id] === 'in_stock')
                            ->sortBy(fn ($m) => $statuses[$m->id] === 'out_of_stock' ? 0 : 1)->values();
                    @endphp
                    <tr class="border-t border-slate-100">
                        <td class="px-5 py-3 text-ink">{{ $variant->variant_name }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ $variant->sku }}</td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            @if(($variant->price ?? $product->base_price) > 0)
                                <span class="text-ink">₱{{ number_format($variant->price ?? $product->base_price, 2) }}</span>
                            @else
                                <span class="text-xs text-slate-400">at counter</span>
                            @endif
                        </td>
                        @if(! $product->track_inventory)
                        {{-- Made to order: there is no stock to count or restock --}}
                        <td class="px-5 py-3 text-right text-slate-400">-</td>
                        <td class="px-5 py-3 text-right text-slate-400">-</td>
                        <td class="px-5 py-3">
                            @if($short->isEmpty())
                                <span title="{{ $recipe }}" class="px-2 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600 whitespace-nowrap">made to order</span>
                            @else
                                @php $out = $statuses[$short[0]->id] === 'out_of_stock'; @endphp
                                <span title="{{ $recipe }}" class="inline-flex items-center gap-1 max-w-[15rem] px-2 py-1 rounded-full text-xs font-medium {{ $out ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }}">
                                    <span class="truncate">{{ $out ? 'Out' : 'Low' }}: {{ $short[0]->name }}</span>
                                    @if($short->count() > 1)<span class="flex-shrink-0 opacity-70">+{{ $short->count() - 1 }}</span>@endif
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-slate-400">-</td>
                        @else
                        <td class="px-5 py-3 text-right font-medium text-ink">{{ $variant->stock_quantity }}</td>
                        <td class="px-5 py-3 text-right text-slate-500">{{ $variant->low_stock_threshold }}</td>
                        <td class="px-5 py-3">
                            @php $status = $variant->stock_status; @endphp
                            <span class="px-2 py-1 rounded-full text-xs font-medium
                                {{ $status=='out_of_stock' ? 'bg-red-100 text-red-700' : ($status=='low_stock' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700') }}">
                                {{ str_replace('_',' ', $status) }}
                            </span>
                        </td>
                        <td class="px-5 py-3">
                            <form method="POST" action="{{ route('inventory.adjust', $variant) }}" class="flex gap-1.5">
                                @csrf
                                <input type="hidden" name="type" value="stock_in">
                                <input type="number" name="quantity" min="1" placeholder="qty"
                                       class="w-16 text-xs rounded-lg border border-slate-300 bg-white">
                                <button title="Add stock" class="text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5 flex items-center justify-center">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @else
                <p class="text-sm text-slate-500 px-5 py-4">
                    This product doesn't track sizes — it's sold as a single item{{ $product->track_inventory ? '' : ', made to order' }}.
                </p>
            @endif
        </div>
    @empty
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
            <i data-lucide="package-search" class="w-8 h-8 text-slate-300 mx-auto mb-3"></i>
            @if(request()->filled('search') || request()->filled('category_id'))
                <p class="text-sm text-slate-500">No products match{{ request()->filled('search') ? ' "' . request('search') . '"' : '' }}{{ request()->filled('category_id') ? ' in this category' : '' }}.</p>
            @else
                <p class="text-sm text-slate-500 mb-3">No products yet.</p>
                <button @click="addingProduct = true" class="text-brand-600 text-sm font-medium">Add your first product →</button>
            @endif
        </div>
    @endforelse
    </div>

    <div class="mt-6">{{ $products->links() }}</div>
    </div>

    {{-- ============ ADD PRODUCT POPOUT ============ --}}
    <div x-show="addingProduct" style="display:none;" x-transition class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 overflow-y-auto" @click.self="closeAddProduct()">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-3xl my-8" x-show="addingProduct">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                <p class="font-semibold text-ink flex items-center gap-2"><i data-lucide="package-plus" class="w-4 h-4 text-brand-600"></i>Add Product</p>
                <button @click="closeAddProduct()" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <form method="POST" action="{{ route('inventory.store') }}" class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
                @csrf
                <input type="hidden" name="_form" value="add-product">

                @if($addOld && $errors->any())
                    <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
                        <p class="font-medium">The product wasn't saved:</p>
                        <ul class="list-disc ml-5 mt-1 space-y-0.5">
                            @foreach(collect($errors->all())->unique() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="text-xs font-medium text-slate-600 mb-1.5 block">Product Name</label>
                        <input type="text" name="name" x-model="name" required placeholder="e.g. Sublimation T-Shirt"
                            class="w-full text-sm rounded-lg border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 mb-1.5 block">SKU</label>
                        <input type="text" name="sku" required placeholder="e.g. TSHIRT-SUB-001" value="{{ $addOld ? old('sku') : '' }}"
                            class="w-full text-sm rounded-lg border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 mb-1.5 block">Base Price (₱)</label>
                        <input type="number" step="0.01" name="base_price" x-model="price" required placeholder="0.00"
                            class="w-full text-sm rounded-lg border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                    </div>

                    <div class="md:col-span-2">
                        <label class="text-xs font-medium text-slate-600 mb-1.5 block">Category</label>
                        <div x-show="!addingCategory" class="flex gap-2">
                            <div class="flex-1">
                                <x-select-menu name="category_id"
                                    :options="collect(['' => 'Select a category'])->union($categories->pluck('name', 'id'))->all()"
                                    :selected="$addOld ? old('category_id') : null"
                                    placeholder="Select a category" />
                            </div>
                            <button type="button" @click="addingCategory = true"
                                    class="text-xs px-3 py-2 border border-slate-300 rounded-lg text-slate-600 whitespace-nowrap flex items-center gap-1">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i> New category
                            </button>
                        </div>
                        {{-- Off while hidden: a name typed and then cancelled was still sent, and won over the category picked --}}
                        <div x-show="addingCategory" style="display:none;" class="flex gap-2">
                            <input type="text" name="new_category_name" placeholder="Type a new category name" :disabled="!addingCategory"
                                value="{{ $addOld ? old('new_category_name') : '' }}"
                                class="flex-1 text-sm rounded-lg border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                            <button type="button" @click="addingCategory = false"
                                    class="text-xs px-3 py-2 border border-slate-300 rounded-lg text-slate-600">Cancel</button>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="text-xs font-medium text-slate-600 mb-1.5 block">Description</label>
                        <textarea name="description" rows="2"
                            class="w-full text-sm rounded-lg border border-slate-300 focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">{{ $addOld ? old('description') : '' }}</textarea>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4">
                    <p class="text-sm font-medium text-ink mb-1">How is this product sold?</p>
                    <div class="space-y-2 mt-2">
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                               :class="trackInventory ? 'border-brand-400 bg-brand-50/50' : 'border-slate-200'">
                            <input type="checkbox" name="track_inventory" value="1" x-model="trackInventory" class="sr-only">
                            <span class="mt-0.5 flex-shrink-0 w-4 h-4 rounded-sm border flex items-center justify-center transition-colors"
                                  :class="trackInventory ? 'bg-brand-600 border-brand-600' : 'border-slate-300 bg-white'">
                                <i data-lucide="check" class="w-3 h-3 text-white" :class="trackInventory ? 'opacity-100' : 'opacity-0'"></i>
                            </span>
                            <div>
                                <p class="text-sm font-medium text-ink">Keep a stock count for this product</p>
                                <p class="text-xs text-slate-500 mt-0.5">Turn ON for anything physically stocked. Turn OFF only for fully custom, made-to-order jobs.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                               :class="hasVariants ? 'border-brand-400 bg-brand-50/50' : 'border-slate-200'">
                            <input type="checkbox" name="has_variants" value="1" x-model="hasVariants" class="sr-only">
                            <span class="mt-0.5 flex-shrink-0 w-4 h-4 rounded-sm border flex items-center justify-center transition-colors"
                                  :class="hasVariants ? 'bg-brand-600 border-brand-600' : 'border-slate-300 bg-white'">
                                <i data-lucide="check" class="w-3 h-3 text-white" :class="hasVariants ? 'opacity-100' : 'opacity-0'"></i>
                            </span>
                            <div>
                                <p class="text-sm font-medium text-ink">This product comes in different sizes</p>
                                <p class="text-xs text-slate-500 mt-0.5">e.g. S/M/L/XL for shirts, or 6in/8in/10in for trophies — each size tracked separately.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div x-show="hasVariants" style="display:none;" class="border-t border-slate-100 pt-4">
                    <p class="text-sm font-medium text-ink mb-1">Sizes for this product</p>
                    <p class="text-xs text-slate-500 mb-3">
                        Each size below is created with 0 stock. Starting quantity is added afterward through
                        a Restock / batch receipt on the Inventory page — not here.
                    </p>

                    <div class="hidden md:grid md:grid-cols-12 gap-2 px-3 mb-1">
                        <label class="md:col-span-4 text-[11px] font-medium text-slate-500 uppercase tracking-wide">Size / Variant</label>
                        <label class="md:col-span-4 text-[11px] font-medium text-slate-500 uppercase tracking-wide">Variant SKU</label>
                        <label class="md:col-span-2 text-[11px] font-medium text-slate-500 uppercase tracking-wide">Price</label>
                        <label class="md:col-span-1 text-[11px] font-medium text-slate-500 uppercase tracking-wide">Warn At</label>
                        <span class="md:col-span-1"></span>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(row, i) in rows" :key="i">
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-start p-3 rounded-lg bg-slate-50">
                                <div class="md:col-span-4">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 uppercase tracking-wide">Size / Variant</label>
                                    <input type="text" :name="`variants[${i}][variant_name]`" x-model="row.name" placeholder="e.g. Medium (M)"
                                        class="w-full text-sm rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                                <div class="md:col-span-4">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 uppercase tracking-wide">Variant SKU</label>
                                    <input type="text" :name="`variants[${i}][sku]`" x-model="row.sku" placeholder="e.g. TSHIRT-SUB-001-M"
                                        class="w-full text-sm rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 uppercase tracking-wide">Price</label>
                                    <input type="number" step="0.01" :name="`variants[${i}][price]`" x-model="row.price" placeholder="Base price if blank"
                                        class="w-full text-sm rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                                <div class="md:col-span-1">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 uppercase tracking-wide">Warning Limit</label>
                                    <input type="number" :name="`variants[${i}][low_stock_threshold]`" x-model="row.threshold" placeholder="5"
                                        class="w-full text-sm rounded-lg border border-slate-300 px-3 py-2">
                                </div>
                                <div class="md:col-span-1 flex justify-end">
                                    <button type="button" @click="rows.splice(i,1)" title="Remove this size"
                                        class="flex-shrink-0 w-9 h-9 rounded-lg border border-red-200 text-red-500 hover:bg-red-50 flex items-center justify-center">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>

                                @if($materials->count())
                                <div class="md:col-span-12 pt-2 mt-1 border-t border-slate-200">
                                    <p class="text-[11px] font-medium text-slate-500 uppercase tracking-wide mb-1.5">
                                        Made from (optional — a shirt can use more than one, e.g. fabric AND ink)
                                    </p>
                                    <div class="space-y-1.5">
                                        <template x-for="(vm, j) in row.materials" :key="j">
                                            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center">
                                                <div class="md:col-span-7">
                                                    <select :name="`variants[${i}][materials][${j}][material_id]`" x-model="vm.material_id"
                                                        class="w-full text-xs rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                                                        <option value="">Select a material</option>
                                                        @foreach($materials as $material)
                                                            <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->unit }})</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="md:col-span-4">
                                                    <input type="number" step="0.001" :name="`variants[${i}][materials][${j}][quantity_per_unit]`" x-model="vm.quantity_per_unit"
                                                        placeholder="Qty used per unit, e.g. 1.2" class="w-full text-xs rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                                                </div>
                                                <div class="md:col-span-1 flex justify-end">
                                                    <button type="button" @click="row.materials.splice(j,1)" title="Remove this material"
                                                        class="w-7 h-7 rounded-lg text-red-500 hover:bg-red-50 flex items-center justify-center">
                                                        <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <button type="button" @click="row.materials.push({material_id:'',quantity_per_unit:''}); $nextTick(() => lucide.createIcons())"
                                        class="mt-1.5 text-[11px] text-brand-600 font-medium flex items-center gap-1">
                                        <i data-lucide="plus" class="w-3 h-3"></i> Add a material
                                    </button>
                                </div>
                                @endif
                            </div>
                        </template>
                    </div>
                    <button type="button" @click="rows.push({name:'',sku:'',price:'',threshold:5,materials:[]}); $nextTick(() => lucide.createIcons())"
                        class="mt-2 text-xs text-brand-600 font-medium flex items-center gap-1">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add a size
                    </button>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-lg px-6 py-2.5">Save Product</button>
                    <button type="button" @click="closeAddProduct()" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg px-6 py-2.5">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- SHADCN-STYLE ALERT DIALOG — Delete confirmation --}}
    <div x-show="confirmingDelete !== null" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingDelete = null">
        <div x-show="confirmingDelete !== null" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-md p-6">
            <p class="text-lg font-semibold text-ink mb-2">Delete this product?</p>
            <p class="text-sm text-slate-500 mb-6">
                <span x-text="confirmingDelete?.name"></span> will be moved to the Archive, not permanently deleted — you can restore it from there at any time.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="confirmingDelete = null"
                    class="border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2">
                    Cancel
                </button>
                <button @click="document.getElementById('delete-product-form-' + confirmingDelete.id).submit()"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Search and category change the list in place (smoothList), like its page links: no reload, and
// the search box keeps its cursor while typing. Typing replaces the address instead of adding to
// the history; a category change adds to it.
window.applyProductFilters = function (history) {
    const form = document.getElementById('inventory-search-form');
    const params = new URLSearchParams(new FormData(form));
    for (const [key, value] of [...params]) if (!value) params.delete(key);
    const query = params.toString();
    smoothList.load(location.pathname + (query ? '?' + query : ''), { history });
};
(function () {
    const input = document.getElementById('inventory-search-input');
    const form = document.getElementById('inventory-search-form');
    // Looked up each time: the icon script swaps the spinner for its drawing after this runs.
    const spinner = () => document.getElementById('inventory-search-spinner');
    let debounceTimer;

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        spinner().style.display = 'block';
        debounceTimer = setTimeout(() => applyProductFilters('replace'), 350);
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearTimeout(debounceTimer);
        applyProductFilters('push');
    });
    document.addEventListener('smooth-list:loaded', () => { spinner().style.display = 'none'; });
    // Back and Forward: the search box and the category follow the address.
    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(location.search);
        input.value = params.get('search') || '';
        const menu = form.querySelector('input[name="category_id"]')?.closest('[x-data]');
        if (menu && window.Alpine) Alpine.$data(menu).value = params.get('category_id') || '';
    });
})();
</script>
@endsection
