@extends('layouts.app')
@section('title', 'Inventory')

@section('content')
<div x-data="{
        addingProduct: false, hasVariants: false, trackInventory: true, addingCategory: false,
        name: '', price: '', rows: [], confirmingDelete: null,
        closeAddProduct() {
            const hasProgress = this.name.trim() !== '' || this.price !== '' || this.rows.length > 0;
            if (hasProgress && !confirm('Leaving now will not save the product you were adding. Are you sure you want to close this?')) {
                return;
            }
            this.addingProduct = false;
        }
     }" x-init="lucide.createIcons()">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <form method="GET" id="inventory-search-form" class="flex gap-2">
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" id="inventory-search-input" value="{{ request('search') }}" placeholder="Search product..." autocomplete="off"
                       class="text-sm rounded-full border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white pl-10 pr-9 py-2.5 w-64 shadow-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <i id="inventory-search-spinner" data-lucide="loader-2" class="w-3.5 h-3.5 text-brand-500 absolute right-3.5 top-1/2 -translate-y-1/2 animate-spin" style="display:none;"></i>
            </div>
            <div class="w-48">
                <x-select-menu name="category_id"
                    :options="collect(['' => 'All categories'])->union($categories->pluck('name', 'id'))->all()"
                    :selected="request('category_id')"
                    placeholder="All categories"
                    onchange="document.getElementById('inventory-search-form').submit()" />
            </div>
        </form>
        <div class="flex gap-2">
            <a href="{{ route('inventory.categories') }}" class="text-sm px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5">
                <i data-lucide="tags" class="w-3.5 h-3.5"></i> Categories
            </a>
            <a href="{{ route('inventory.materials') }}" class="text-sm px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5">
                <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Raw Materials
            </a>
            <a href="{{ route('inventory.archive') }}" class="text-sm px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5">
                <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
            </a>
            <button @click="addingProduct = true" class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg px-4 py-2 flex items-center gap-1.5">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Product
            </button>
        </div>
    </div>

    <div class="space-y-4">
    @forelse($products as $product)
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="flex justify-between items-center px-5 py-4 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-brand-50 dark:bg-brand-600/20 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="package" class="w-5 h-5 text-brand-600"></i>
                    </div>
                    <div>
                        <p class="font-medium text-ink dark:text-white">{{ $product->name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">SKU: {{ $product->sku }} · {{ $product->category->name ?? 'Uncategorized' }}</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm font-semibold text-ink dark:text-white">₱{{ number_format($product->base_price,2) }}</p>
                    <p class="text-xs text-slate-400">base price</p>
                </div>
                <form method="POST" action="{{ route('inventory.destroy', $product) }}" class="ml-3" id="delete-product-form-{{ $product->id }}">
                    @csrf @method('DELETE')
                    <button type="button" title="Delete product"
                        @click="confirmingDelete = { id: {{ $product->id }}, name: {{ json_encode($product->name) }} }"
                        class="w-8 h-8 rounded-lg border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center">
                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                    </button>
                </form>
            </div>

            @if($product->has_variants)
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/40">
                        <th class="px-5 py-2.5 font-medium">Size / Variant</th>
                        <th class="px-5 py-2.5 font-medium">SKU</th>
                        <th class="px-5 py-2.5 font-medium text-right">Stock</th>
                        <th class="px-5 py-2.5 font-medium text-right">Low Stock At</th>
                        <th class="px-5 py-2.5 font-medium">Status</th>
                        <th class="px-5 py-2.5 font-medium">Restock</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($product->variants as $variant)
                    <tr class="border-t border-slate-100 dark:border-slate-700">
                        <td class="px-5 py-3 text-ink dark:text-white">
                            {{ $variant->variant_name }}
                            @foreach($variant->materials as $material)
                                @php $materialStatus = $material->stock_status; @endphp
                                <p class="text-[11px] flex items-center gap-1 mt-0.5 {{ $materialStatus === 'in_stock' ? 'text-slate-400' : ($materialStatus === 'low_stock' ? 'text-amber-600' : 'text-red-600 font-medium') }}">
                                    <i data-lucide="{{ $materialStatus === 'in_stock' ? 'scissors' : 'alert-triangle' }}" class="w-2.5 h-2.5"></i>
                                    {{ rtrim(rtrim(number_format($material->pivot->quantity_per_unit, 3), '0'), '.') }} {{ $material->unit }} of {{ $material->name }} per unit
                                    @if($materialStatus !== 'in_stock')
                                        — {{ $materialStatus === 'out_of_stock' ? 'material out of stock!' : 'material running low' }}
                                    @endif
                                </p>
                            @endforeach
                        </td>
                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $variant->sku }}</td>
                        <td class="px-5 py-3 text-right font-medium text-ink dark:text-white">{{ $variant->stock_quantity }}</td>
                        <td class="px-5 py-3 text-right text-slate-500 dark:text-slate-400">{{ $variant->low_stock_threshold }}</td>
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
                                       class="w-16 text-xs rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white">
                                <button title="Add stock" class="text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5 flex items-center justify-center">
                                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400 px-5 py-4">This product doesn't track sizes — it's sold as a single item.</p>
            @endif
        </div>
    @empty
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-10 text-center">
            <i data-lucide="package-search" class="w-8 h-8 text-slate-300 mx-auto mb-3"></i>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">No products yet.</p>
            <button @click="addingProduct = true" class="text-brand-600 text-sm font-medium">Add your first product →</button>
        </div>
    @endforelse
    </div>

    <div class="mt-4">{{ $products->links() }}</div>

    {{-- ============ ADD PRODUCT POPOUT ============ --}}
    <div x-show="addingProduct" style="display:none;" x-transition class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 overflow-y-auto" @click.self="closeAddProduct()">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-3xl my-8" x-show="addingProduct">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-700">
                <p class="font-semibold text-ink dark:text-white flex items-center gap-2"><i data-lucide="package-plus" class="w-4 h-4 text-brand-600"></i>Add Product</p>
                <button @click="closeAddProduct()" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <form method="POST" action="{{ route('inventory.store') }}" class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">Product Name</label>
                        <input type="text" name="name" x-model="name" required placeholder="e.g. Sublimation T-Shirt"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">SKU</label>
                        <input type="text" name="sku" required placeholder="e.g. TSHIRT-SUB-001"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">Base Price (₱)</label>
                        <input type="number" step="0.01" name="base_price" x-model="price" required placeholder="0.00"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                    </div>

                    <div class="md:col-span-2">
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">Category</label>
                        <div x-show="!addingCategory" class="flex gap-2">
                            <div class="flex-1">
                                <x-select-menu name="category_id"
                                    :options="collect(['' => 'Select a category'])->union($categories->pluck('name', 'id'))->all()"
                                    placeholder="Select a category" />
                            </div>
                            <button type="button" @click="addingCategory = true"
                                    class="text-xs px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-600 dark:text-slate-300 whitespace-nowrap flex items-center gap-1">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i> New category
                            </button>
                        </div>
                        <div x-show="addingCategory" style="display:none;" class="flex gap-2">
                            <input type="text" name="new_category_name" placeholder="Type a new category name"
                                class="flex-1 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                            <button type="button" @click="addingCategory = false"
                                    class="text-xs px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-600 dark:text-slate-300">Cancel</button>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">Description</label>
                        <textarea name="description" rows="2"
                            class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5"></textarea>
                    </div>
                </div>

                <div class="border-t border-slate-100 dark:border-slate-700 pt-4">
                    <p class="text-sm font-medium text-ink dark:text-white mb-1">How is this product sold?</p>
                    <div class="space-y-2 mt-2">
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                               :class="trackInventory ? 'border-brand-400 bg-brand-50/50 dark:bg-brand-600/10' : 'border-slate-200 dark:border-slate-600'">
                            <input type="checkbox" name="track_inventory" value="1" x-model="trackInventory" class="sr-only">
                            <span class="mt-0.5 flex-shrink-0 w-4 h-4 rounded-sm border flex items-center justify-center transition-colors"
                                  :class="trackInventory ? 'bg-brand-600 border-brand-600' : 'border-slate-300 dark:border-slate-500 bg-white dark:bg-slate-700'">
                                <i data-lucide="check" class="w-3 h-3 text-white" :class="trackInventory ? 'opacity-100' : 'opacity-0'"></i>
                            </span>
                            <div>
                                <p class="text-sm font-medium text-ink dark:text-white">Keep a stock count for this product</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Turn ON for anything physically stocked. Turn OFF only for fully custom, made-to-order jobs.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                               :class="hasVariants ? 'border-brand-400 bg-brand-50/50 dark:bg-brand-600/10' : 'border-slate-200 dark:border-slate-600'">
                            <input type="checkbox" name="has_variants" value="1" x-model="hasVariants" class="sr-only">
                            <span class="mt-0.5 flex-shrink-0 w-4 h-4 rounded-sm border flex items-center justify-center transition-colors"
                                  :class="hasVariants ? 'bg-brand-600 border-brand-600' : 'border-slate-300 dark:border-slate-500 bg-white dark:bg-slate-700'">
                                <i data-lucide="check" class="w-3 h-3 text-white" :class="hasVariants ? 'opacity-100' : 'opacity-0'"></i>
                            </span>
                            <div>
                                <p class="text-sm font-medium text-ink dark:text-white">This product comes in different sizes</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">e.g. S/M/L/XL for shirts, or 6in/8in/10in for trophies — each size tracked separately.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div x-show="hasVariants" style="display:none;" class="border-t border-slate-100 dark:border-slate-700 pt-4">
                    <p class="text-sm font-medium text-ink dark:text-white mb-1">Sizes for this product</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Each size below is tracked as its own stock count — running out of Medium won't affect Large.</p>

                    <div class="hidden md:grid md:grid-cols-12 gap-2 px-3 mb-1">
                        <label class="md:col-span-3 text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Size / Variant</label>
                        <label class="md:col-span-3 text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Variant SKU</label>
                        <label class="md:col-span-2 text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Price</label>
                        <label class="md:col-span-2 text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Stock Qty</label>
                        <label class="md:col-span-1 text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Warn At</label>
                        <span class="md:col-span-1"></span>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(row, i) in rows" :key="i">
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-start p-3 rounded-lg bg-slate-50 dark:bg-slate-700/40">
                                <div class="md:col-span-3">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Size / Variant</label>
                                    <input type="text" :name="`variants[${i}][variant_name]`" x-model="row.name" placeholder="e.g. Medium (M)"
                                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                                </div>
                                <div class="md:col-span-3">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Variant SKU</label>
                                    <input type="text" :name="`variants[${i}][sku]`" x-model="row.sku" placeholder="e.g. TSHIRT-SUB-001-M"
                                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Price</label>
                                    <input type="number" step="0.01" :name="`variants[${i}][price]`" x-model="row.price" placeholder="Base price if blank"
                                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Stock Quantity</label>
                                    <input type="number" :name="`variants[${i}][stock_quantity]`" x-model="row.stock" placeholder="0"
                                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                                </div>
                                <div class="md:col-span-1">
                                    <label class="md:hidden text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Warning Limit</label>
                                    <input type="number" :name="`variants[${i}][low_stock_threshold]`" x-model="row.threshold" placeholder="5"
                                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                                </div>
                                <div class="md:col-span-1 flex justify-end">
                                    <button type="button" @click="rows.splice(i,1)" title="Remove this size"
                                        class="flex-shrink-0 w-9 h-9 rounded-lg border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>

                                @if($materials->count())
                                <div class="md:col-span-12 pt-2 mt-1 border-t border-slate-200 dark:border-slate-600">
                                    <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                                        Made from (optional — a shirt can use more than one, e.g. fabric AND ink)
                                    </p>
                                    <div class="space-y-1.5">
                                        <template x-for="(vm, j) in row.materials" :key="j">
                                            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center">
                                                <div class="md:col-span-7">
                                                    <select :name="`variants[${i}][materials][${j}][material_id]`" x-model="vm.material_id"
                                                        class="w-full text-xs rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 dark:text-white px-2 py-1.5">
                                                        <option value="">Select a material</option>
                                                        @foreach($materials as $material)
                                                            <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->unit }})</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="md:col-span-4">
                                                    <input type="number" step="0.001" :name="`variants[${i}][materials][${j}][quantity_per_unit]`" x-model="vm.quantity_per_unit"
                                                        placeholder="Qty used per unit, e.g. 1.2" class="w-full text-xs rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 dark:text-white px-2 py-1.5">
                                                </div>
                                                <div class="md:col-span-1 flex justify-end">
                                                    <button type="button" @click="row.materials.splice(j,1)" title="Remove this material"
                                                        class="w-7 h-7 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center">
                                                        <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <button type="button" @click="row.materials.push({material_id:'',quantity_per_unit:''})"
                                        class="mt-1.5 text-[11px] text-brand-600 font-medium flex items-center gap-1">
                                        <i data-lucide="plus" class="w-3 h-3"></i> Add a material
                                    </button>
                                </div>
                                @endif
                            </div>
                        </template>
                    </div>
                    <button type="button" @click="rows.push({name:'',sku:'',price:'',stock:'',threshold:5,materials:[]})"
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
             class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6">
            <p class="text-lg font-semibold text-ink dark:text-white mb-2">Delete this product?</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                <span x-text="confirmingDelete?.name"></span> will be moved to the Archive, not permanently deleted — you can restore it from there at any time.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="confirmingDelete = null"
                    class="border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2">
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
(function () {
    const input = document.getElementById('inventory-search-input');
    const form = document.getElementById('inventory-search-form');
    const spinner = document.getElementById('inventory-search-spinner');
    let debounceTimer;

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        spinner.style.display = 'block';
        debounceTimer = setTimeout(function () {
            form.submit();
        }, 400);
    });
})();
</script>
@endsection
