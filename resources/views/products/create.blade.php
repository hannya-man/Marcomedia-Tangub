@extends('layouts.app')
@section('title', 'Add Product')

@section('content')
<form method="POST" action="{{ route('inventory.store') }}"
      x-data="{ hasVariants: false, trackInventory: true, addingCategory: false, name: '', price: '', totalStock: 0, rows: [] }"
      class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    @csrf

    <div class="lg:col-span-2 space-y-5">

        {{-- Basic Info --}}
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
            <p class="font-semibold text-ink dark:text-white mb-5">Basic Information</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
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

                {{-- Category, with an inline "add new" option so a short list is never a dead end --}}
                <div class="md:col-span-2">
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">Category</label>
                    <div x-show="!addingCategory" class="flex gap-2">
                        <select name="category_id" class="flex-1 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                            <option value="">Select a category</option>
                            @foreach($categories as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" @click="addingCategory = true"
                                class="text-xs px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-600 dark:text-slate-300 whitespace-nowrap flex items-center gap-1">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i> New category
                        </button>
                    </div>
                    <div x-show="addingCategory" style="display:none;" class="flex gap-2">
                        <input type="text" name="new_category_name" placeholder="Type a new category name, e.g. Home Decor"
                            class="flex-1 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5">
                        <button type="button" @click="addingCategory = false"
                                class="text-xs px-3 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-600 dark:text-slate-300">Cancel</button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Only {{ $categories->count() }} categor{{ $categories->count() === 1 ? 'y exists' : 'ies exist' }} so far — click "New category" to add one on the spot.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5 block">Description</label>
                    <textarea name="description" rows="3" placeholder="Short description of the product..."
                        class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:ring-2 focus:ring-brand-500 focus:border-brand-500 px-3.5 py-2.5"></textarea>
                </div>
            </div>
        </div>

        {{-- Product Image --}}
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
            <p class="font-semibold text-ink dark:text-white mb-5">Product Image</p>
            <label for="product_image" class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-brand-400 hover:bg-brand-50/40 dark:hover:bg-brand-600/10 rounded-xl py-10 cursor-pointer transition-colors">
                <i data-lucide="upload-cloud" class="w-6 h-6 text-slate-400"></i>
                <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Click to upload an image</p>
                <p class="text-xs text-slate-400">PNG or JPG, up to 2MB</p>
                <input id="product_image" type="file" name="image" accept="image/*" class="hidden">
            </label>
        </div>

        {{-- Stock behavior — plain-language explanation instead of jargon --}}
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
            <p class="font-semibold text-ink dark:text-white mb-1">How is this product sold?</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-5">This decides how stock is counted when it's sold.</p>

            <div class="space-y-3">
                <label class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors"
                       :class="trackInventory ? 'border-brand-400 bg-brand-50/50 dark:bg-brand-600/10' : 'border-slate-200 dark:border-slate-600'">
                    <input type="checkbox" name="track_inventory" value="1" x-model="trackInventory" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600">
                    <div>
                        <p class="text-sm font-medium text-ink dark:text-white">Keep a stock count for this product</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Turn this ON for anything you physically stock — t-shirts, mugs, printed materials.
                            Every sale will subtract from stock automatically, and you'll get a low-stock warning
                            when it runs out. Turn this OFF only for fully custom, made-to-order jobs where there's
                            no physical stock to run out of (e.g. a one-off engraving job).
                        </p>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-4 rounded-lg border cursor-pointer transition-colors"
                       :class="hasVariants ? 'border-brand-400 bg-brand-50/50 dark:bg-brand-600/10' : 'border-slate-200 dark:border-slate-600'">
                    <input type="checkbox" name="has_variants" value="1" x-model="hasVariants" class="mt-0.5 w-4 h-4 rounded border-slate-300 text-brand-600">
                    <div>
                        <p class="text-sm font-medium text-ink dark:text-white">This product comes in different sizes</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Turn this ON if different sizes need their own stock count — like t-shirt sizes
                            (S/M/L/XL) or trophy heights (6in/8in/10in). Each size you add below gets tracked
                            separately, so running out of Medium doesn't mean you're out of Large.
                        </p>
                    </div>
                </label>
            </div>
        </div>

        {{-- Variants --}}
        <div x-show="hasVariants" style="display:none;" class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6">
            <p class="font-semibold text-ink dark:text-white mb-1">Sizes for this product</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-5">Add one row per size, with its own starting stock and low-stock warning level.</p>

            <div class="space-y-2">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center p-3 rounded-lg bg-slate-50 dark:bg-slate-700/40">
                        <input type="text" :name="`variants[${i}][variant_name]`" x-model="row.name" placeholder="Size, e.g. Large"
                            class="md:col-span-3 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                        <input type="text" :name="`variants[${i}][sku]`" x-model="row.sku" placeholder="Variant SKU"
                            class="md:col-span-3 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                        <input type="number" step="0.01" :name="`variants[${i}][price]`" x-model="row.price" placeholder="Price (optional)"
                            class="md:col-span-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                        <input type="number" :name="`variants[${i}][stock_quantity]`" x-model="row.stock" placeholder="Stock"
                            class="md:col-span-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                        <input type="number" :name="`variants[${i}][low_stock_threshold]`" x-model="row.threshold" placeholder="Low at"
                            class="md:col-span-1 text-sm rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-white px-3 py-2">
                        <button type="button" @click="rows.splice(i,1)" class="md:col-span-1 text-red-500 text-xs">Remove</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="rows.push({name:'',sku:'',price:'',stock:'',threshold:5})"
                class="mt-3 text-xs text-brand-600 font-medium flex items-center gap-1">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add a size
            </button>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg px-6 py-2.5">Save Product</button>
            <a href="{{ route('inventory.index') }}" class="border border-slate-300 dark:border-slate-600 text-sm rounded-lg px-6 py-2.5 text-slate-600 dark:text-slate-300">Cancel</a>
        </div>
    </div>

    {{-- Live preview --}}
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-6 h-fit sticky top-6">
        <p class="font-semibold text-ink dark:text-white mb-4">Preview</p>
        <div class="h-32 rounded-lg bg-slate-100 dark:bg-slate-700 mb-4 flex items-center justify-center text-slate-300">
            <i data-lucide="image" class="w-6 h-6"></i>
        </div>
        <p class="text-sm font-medium text-ink dark:text-white" x-text="name || 'Product name'"></p>
        <p class="text-lg font-semibold text-ink dark:text-white mt-1" x-text="'₱' + (parseFloat(price) || 0).toFixed(2)"></p>
        <div class="border-t border-slate-100 dark:border-slate-700 mt-4 pt-4 text-xs text-slate-500 dark:text-slate-400 space-y-1">
            <p x-show="trackInventory">✓ Stock will be tracked</p>
            <p x-show="!trackInventory">— Made to order, no stock count</p>
            <p x-show="hasVariants" x-text="rows.length + ' size(s) configured'"></p>
        </div>
    </div>
</form>
@endsection
