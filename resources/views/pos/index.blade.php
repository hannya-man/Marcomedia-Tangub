@extends('layouts.app')
@section('title', 'Point of Sale')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="posCart()" x-init="trackUnsavedCart()">

    <!-- Product picker -->
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
        <div class="relative mb-4">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
            <input type="text" x-model="search" placeholder="Search product..."
                   class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white text-sm pl-9 pr-3 py-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 max-h-[70vh] overflow-y-auto">
            @foreach($products as $product)
            <div class="flex flex-col rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm hover:shadow-md hover:border-brand-400 dark:hover:border-brand-400 transition-all overflow-hidden"
                 x-show="'{{ strtolower($product->name) }}'.includes(search.toLowerCase())">

                {{-- Card header --}}
                <div class="px-3.5 pt-3.5 pb-2">
                    <p class="font-medium text-sm text-ink dark:text-white leading-snug">{{ $product->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">₱{{ number_format($product->base_price, 2) }}</p>
                </div>

                {{-- Card content --}}
                @if($product->has_variants && $product->variants->count())
                <div class="px-3.5 pb-2">
                    <select class="w-full text-xs rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-2 py-1.5" x-ref="variant_{{ $product->id }}">
                        @foreach($product->variants as $variant)
                            <option value="{{ $variant->id }}"
                                data-price="{{ $variant->price ?? $product->base_price }}"
                                data-stock="{{ $variant->stock_quantity }}"
                                data-name="{{ $variant->variant_name }}"
                                {{ $variant->stock_quantity <= 0 ? 'disabled' : '' }}>
                                {{ $variant->variant_name }} — {{ $variant->stock_quantity }} in stock
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif

                {{-- Card footer --}}
                <div class="px-3.5 pb-3.5 pt-1 mt-auto border-t border-slate-100 dark:border-slate-700/60">
                    <button type="button"
                        @click="addItem({{ $product->id }}, '{{ $product->name }}', {{ $product->base_price }}, {{ $product->has_variants ? 'true' : 'false' }}, {{ $product->track_inventory ? 'true' : 'false' }})"
                        class="w-full text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-md py-1.5 mt-2 flex items-center justify-center gap-1">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add to cart
                    </button>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Cart -->
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col">
        <p class="font-medium text-ink dark:text-white mb-3">Current Sale</p>

        <div class="flex-1 overflow-y-auto space-y-3 max-h-[45vh]">
            <template x-for="(item, idx) in cart" :key="idx">
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/40 p-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-ink dark:text-white truncate" x-text="item.name"></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400" x-text="item.variant_name || ''"></p>
                        </div>
                        <button type="button" @click="cart.splice(idx,1); recalc()" title="Remove from cart"
                            class="flex-shrink-0 w-7 h-7 rounded-md border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </div>

                    <textarea x-model="item.customization_details" placeholder="Customization notes (e.g. engraving text)"
                        class="mt-2 w-full text-xs rounded-lg border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-2 py-1.5"></textarea>

                    <div class="flex items-center justify-between mt-2">
                        {{-- Quantity stepper — clearer and more touch-friendly than a bare number box --}}
                        <div class="flex items-center rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 overflow-hidden">
                            <button type="button" @click="item.quantity = Math.max(1, item.quantity - 1); recalc()"
                                class="w-8 h-8 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">
                                <i data-lucide="minus" class="w-3.5 h-3.5"></i>
                            </button>
                            <input type="number" min="1" x-model.number="item.quantity" @input="recalc()"
                                class="w-10 text-center text-sm border-0 focus:ring-0 bg-transparent dark:text-white px-0 py-1.5">
                            <button type="button" @click="item.quantity++; recalc()"
                                class="w-8 h-8 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                        <p class="text-sm font-medium text-ink dark:text-white" x-text="'₱' + (item.price * item.quantity).toFixed(2)"></p>
                    </div>
                </div>
            </template>
            <p x-show="cart.length === 0" class="text-sm text-slate-400">Cart is empty.</p>
        </div>

        <form :action="'{{ route('pos.store') }}'" method="POST" @submit="handleSubmit($event)" class="mt-4 space-y-3 border-t border-slate-100 dark:border-slate-700 pt-4">
            @csrf
            <template x-for="(item, idx) in cart" :key="idx">
                <div>
                    <input type="hidden" :name="`items[${idx}][product_id]`" :value="item.product_id">
                    <input type="hidden" :name="`items[${idx}][product_variant_id]`" :value="item.variant_id">
                    <input type="hidden" :name="`items[${idx}][quantity]`" :value="item.quantity">
                    <input type="hidden" :name="`items[${idx}][unit_price]`" :value="item.price">
                    <input type="hidden" :name="`items[${idx}][customization_details]`" :value="item.customization_details">
                </div>
            </template>

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1 block">Customer</label>
                <x-select-menu name="customer_id"
                    :options="collect(['' => 'Walk-in customer'])->union($customers->pluck('name', 'id'))->all()"
                    placeholder="Walk-in customer" />
            </div>

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300 mb-1 block">Payment Method</label>
                <x-select-menu name="payment_method"
                    :options="['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card', 'bank_transfer' => 'Bank Transfer']"
                    :selected="'cash'" />
            </div>

            <div class="flex justify-between text-sm pt-2 text-ink dark:text-white">
                <span>Subtotal</span><span x-text="'₱' + subtotal.toFixed(2)"></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-ink dark:text-white">Discount</span>
                <input type="number" name="discount_amount" x-model.number="discount" step="0.01" min="0"
                       class="w-24 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white text-right px-2 py-1.5">
            </div>
            <div class="flex justify-between font-semibold text-ink dark:text-white text-base">
                <span>Total</span><span x-text="'₱' + total.toFixed(2)"></span>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Amount Paid</label>
                    <div class="flex gap-2">
                        <button type="button" @click="amountPaid = Math.round(total * 0.5 * 100) / 100" class="text-[10px] text-brand-600 font-medium">50% down payment</button>
                        <button type="button" @click="amountPaid = total" class="text-[10px] text-brand-600 font-medium">Use exact amount</button>
                    </div>
                </div>
                <input type="number" name="amount_paid" x-model.number="amountPaid" step="0.01" placeholder="0.00" min="0.01"
                       class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                       :class="amountPaid > 0 && amountPaid < total ? 'border-amber-400' : ''">
                <p x-show="amountPaid > 0 && amountPaid < total" class="text-xs text-amber-600 mt-1">
                    Down payment — balance of ₱<span x-text="(total - amountPaid).toFixed(2)"></span> still owed. This sale will be marked <strong>Pending</strong> until it's paid in full.
                </p>
                <p x-show="amountPaid >= total && amountPaid > 0" class="text-xs text-emerald-600 mt-1">
                    Paid in full. Change: ₱<span x-text="(amountPaid - total).toFixed(2)"></span>
                </p>
            </div>

            <button type="submit" :disabled="cart.length === 0 || amountPaid <= 0"
                class="w-full bg-ink hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg py-2.5 text-sm font-medium mt-2">
                <span x-text="amountPaid > 0 && amountPaid < total ? 'Record Down Payment' : 'Complete Sale'"></span>
            </button>
        </form>
    </div>
</div>

<script>
function posCart() {
    return {
        search: '',
        cart: [],
        subtotal: 0,
        discount: 0,
        amountPaid: 0,
        submitted: false,
        get total() {
            return Math.max(this.subtotal - (this.discount || 0), 0);
        },
        addItem(productId, name, basePrice, hasVariants, trackInventory) {
            let variantId = null, variantName = null, price = basePrice, stock = null;
            if (hasVariants) {
                const select = this.$refs['variant_' + productId];
                if (select) {
                    const opt = select.options[select.selectedIndex];
                    variantId = select.value;
                    variantName = opt.dataset.name;
                    price = parseFloat(opt.dataset.price);
                    stock = parseInt(opt.dataset.stock);
                    if (trackInventory && stock <= 0) { alert('That size is out of stock.'); return; }
                }
            }

            // Same product + same size already in cart? Bump the quantity
            // instead of adding a second row for it.
            const existing = this.cart.find(i => i.product_id === productId && i.variant_id === variantId);
            if (existing) {
                existing.quantity += 1;
            } else {
                this.cart.push({
                    product_id: productId, variant_id: variantId, name, variant_name: variantName,
                    price, quantity: 1, customization_details: ''
                });
            }
            this.recalc();
        },
        recalc() {
            this.subtotal = this.cart.reduce((sum, i) => sum + (i.price * i.quantity), 0);
        },
        handleSubmit(e) {
            if (this.amountPaid <= 0) {
                e.preventDefault();
                alert('Please enter an amount paid (a full payment or a down payment) before completing the sale.');
                return;
            }
            this.submitted = true;
        },
        get hasProgress() {
            return this.cart.length > 0;
        },
        trackUnsavedCart() {
            window.addEventListener('beforeunload', (e) => {
                if (this.hasProgress && !this.submitted) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        }
    }
}
</script>
@endsection
