@extends('layouts.app')
@section('title', 'Point of Sale')
@section('shows-errors', '1')

@section('content')
@php
    // Cart icons are drawn inline: Lucide only converts icons on the page when it loads,
    // not ones Alpine adds later, which left the cart's remove and +/- buttons blank.
    $svg = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    $label = 'text-xs font-medium text-slate-600';
    $input = 'w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    $counts = $products->countBy(fn ($p) => (string) ($p->category_id ?? ''));
@endphp

@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
        {{ $errors->first() }}
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="posCart()">

    <!-- Product picker -->
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-5">
        <div class="relative mb-3">
            <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
            <input type="text" x-model="search" placeholder="Search item, size or category..." autocomplete="off"
                   class="w-full rounded-lg border border-slate-300 bg-white text-sm pl-9 pr-9 py-2.5 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            <button type="button" x-show="search" style="display:none;" @click="search = ''" title="Clear search" aria-label="Clear search"
                class="absolute right-2 top-1/2 -translate-y-1/2 w-6 h-6 rounded-md flex items-center justify-center text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-3.5 h-3.5"></i>
            </button>
        </div>

        {{-- Category pills, in the shop's order, with how many items each has --}}
        <div class="flex gap-2 mb-4 overflow-x-auto pb-1">
            <button type="button" @click="category = ''" :class="category === '' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                class="text-xs px-3 py-1.5 rounded-full whitespace-nowrap flex-shrink-0">All <span class="opacity-70">{{ $products->count() }}</span></button>
            @foreach($categories as $c)
                @continue(! $counts->has((string) $c->id))
                <button type="button" @click="category = @js((string) $c->id)" :class="category === @js((string) $c->id) ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'"
                    class="text-xs px-3 py-1.5 rounded-full whitespace-nowrap flex-shrink-0">{{ $c->name }} <span class="opacity-70">{{ $counts[(string) $c->id] }}</span></button>
            @endforeach
        </div>

        <div class="space-y-5 lg:max-h-[65vh] lg:overflow-y-auto lg:pr-1">
            {{-- One section per category; products already come in the shop's order.
                 PHP turns the "5" keys back into numbers, hence the string cast for the script. --}}
            @foreach($products->groupBy(fn ($p) => (string) ($p->category_id ?? '')) as $catKey => $group)
            <section x-show="groupVisible(@js((string) $catKey))">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-2">
                    {{ $group->first()->category->name ?? 'Other' }}
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                    @foreach($group as $product)
                    @php
                        $prices = $product->has_variants && $product->variants->count()
                            ? $product->variants->map(fn ($v) => (float) ($v->price ?? $product->base_price))
                            : collect([(float) $product->base_price]);
                        // A size priced at the counter ("Custom size") isn't the lowest price.
                        $priced = $prices->filter(fn ($p) => $p > 0);
                    @endphp
                    <div x-show="visible({{ $product->id }})"
                         class="flex flex-col rounded-lg border border-slate-200 bg-white shadow-sm hover:border-brand-400 transition-colors p-3">
                        <p class="font-medium text-sm text-ink leading-snug">{{ $product->name }}</p>
                        <p class="text-xs mt-0.5">
                            @if($priced->isEmpty())
                                <span class="text-amber-600">Price at counter</span>
                            @elseif($priced->count() < $prices->count() || $priced->min() != $priced->max())
                                <span class="text-slate-600">From ₱{{ number_format($priced->min(), 2) }}</span>
                            @else
                                <span class="text-slate-600">₱{{ number_format($priced->min(), 2) }}</span>
                            @endif
                            @unless($product->track_inventory)
                                <span class="text-slate-400"> · Made to order</span>
                            @endunless
                        </p>

                        @php
                            // Its sizes/types: how many can be sold from stock now (null = no limit), and
                            // what's out. One that's out still sells, on order.
                            $types = collect($catalog[$product->id]['variants']);
                            $isOut = fn ($t) => $t['stock'] !== null && $t['stock'] <= 0;
                        @endphp
                        @if($types->count() > 1)
                            <select x-model.number="sel[{{ $product->id }}]" aria-label="Size or type of {{ $product->name }}"
                                class="mt-2 w-full text-xs rounded-md border border-slate-300 bg-white px-2 py-1.5">
                                @foreach($types as $id => $t)
                                    <option value="{{ $id }}">
                                        {{ $t['name'] }}@if($t['price'] > 0) · ₱{{ number_format($t['price'], 2) }}@endif @if($isOut($t)) — out of stock, on order @elseif($product->track_inventory) — {{ $t['stock'] }} left @endif
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        @if($types->contains(fn ($t) => $t['custom']))
                            {{-- Custom size: charged as the nearest standard piece up, or whole feet of a roll --}}
                            <div x-show="isCustom({{ $product->id }})" style="display:none;" class="mt-2">
                                <div class="grid grid-cols-2 gap-1">
                                    <input type="number" step="0.01" min="0.01" x-model="custom[{{ $product->id }}].w" placeholder="Width" aria-label="Width"
                                        class="w-full min-w-0 text-xs rounded-md border border-slate-300 bg-white px-2 py-1.5">
                                    <input type="number" step="0.01" min="0.01" x-model="custom[{{ $product->id }}].h" placeholder="Height" aria-label="Height"
                                        class="w-full min-w-0 text-xs rounded-md border border-slate-300 bg-white px-2 py-1.5">
                                </div>
                                <div class="mt-1 flex items-center gap-3 text-[11px] text-slate-600">
                                    @foreach(['in' => 'inches', 'ft' => 'feet'] as $unit => $unitLabel)
                                        <label class="inline-flex items-center gap-1 cursor-pointer">
                                            <input type="radio" value="{{ $unit }}" x-model="custom[{{ $product->id }}].unit" class="w-3 h-3 text-brand-600 focus:ring-brand-500"> {{ $unitLabel }}
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-1 text-[11px] leading-snug"
                                   :class="customFit({{ $product->id }}) && customFit({{ $product->id }}).ok ? 'text-slate-500' : 'text-amber-600'"
                                   x-text="customFit({{ $product->id }}) ? customFit({{ $product->id }}).note : 'Enter the size.'"></p>
                            </div>
                        @endif
                        @if($types->contains($isOut))
                            {{-- The picked size is out: it still sells, on order. Say so before it goes in the cart. --}}
                            <p x-show="outNote({{ $product->id }})" style="display:none;" class="mt-2 text-[11px] leading-snug text-amber-600"
                               x-text="outNote({{ $product->id }})"></p>
                        @endif

                        <div class="mt-auto pt-3">
                            <button type="button" @click="addItem({{ $product->id }})"
                                class="w-full text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-md py-1.5 flex items-center justify-center gap-1">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </section>
            @endforeach

            <div x-show="noResults()" style="display:none;" class="text-center py-10">
                <i data-lucide="package-search" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
                <p class="text-sm text-slate-500">Nothing matches "<span x-text="search"></span>".</p>
                <p class="text-xs text-slate-400 mt-1">Not on the list? Use Any Printable Item and describe it in the notes.</p>
            </div>
        </div>
    </div>

    <!-- Cart -->
    <div id="pos-cart" class="bg-white rounded-xl border border-slate-200 flex flex-col overflow-hidden self-start scroll-mt-4">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-2">
            <p class="font-medium text-ink flex items-center gap-2">
                <i data-lucide="shopping-cart" class="w-4 h-4 text-brand-600"></i> Current Sale
            </p>
            <div class="flex items-center gap-2" x-show="cart.length > 0" style="display:none;">
                <span x-text="itemCount() + (itemCount() === 1 ? ' item' : ' items')"
                      class="text-xs bg-brand-50 text-brand-700 rounded-full px-2.5 py-1"></span>
                <button type="button" @click="clearCart()" class="text-xs text-slate-500 hover:text-red-600">Clear</button>
            </div>
        </div>

        <div class="flex-1 space-y-3 p-5 lg:max-h-[42vh] lg:overflow-y-auto">
            <template x-for="(item, idx) in cart" :key="item.uid">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-ink truncate" x-text="item.name"></p>
                            <p class="text-xs text-slate-500" x-show="item.variant_name" x-text="item.variant_name"></p>
                            <p class="text-[11px] text-slate-400" x-show="item.size_note" x-text="item.size_note"></p>
                            <span x-show="onOrder(item)" style="display:none;"
                                  class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-100 text-amber-700">On order</span>
                        </div>
                        <button type="button" @click="removeItem(idx)" title="Remove from cart" aria-label="Remove from cart"
                            class="flex-shrink-0 w-7 h-7 rounded-md border border-red-200 text-red-500 hover:bg-red-50 flex items-center justify-center">
                            <svg {!! $svg !!} class="w-3.5 h-3.5"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        </button>
                    </div>

                    <textarea x-model="item.customization_details" rows="1" placeholder="Details: name, text, design, size..."
                        class="mt-2 w-full text-xs rounded-lg border border-slate-200 bg-white px-2 py-1.5"></textarea>

                    <div class="flex items-center justify-between gap-2 mt-2">
                        {{-- Quantity stepper --}}
                        <div class="flex items-center rounded-lg border border-slate-300 bg-white overflow-hidden flex-shrink-0">
                            <button type="button" @click="item.quantity = Math.max(1, item.quantity - 1); recalc()" aria-label="One less"
                                class="w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-100">
                                <svg {!! $svg !!} class="w-3.5 h-3.5"><path d="M5 12h14"/></svg>
                            </button>
                            <input type="number" min="1" x-model.number="item.quantity" @input="recalc()" @change="clampQuantity(item)" aria-label="Quantity"
                                class="w-10 text-center text-sm border-0 focus:ring-0 bg-transparent px-0 py-1.5">
                            <button type="button" @click="item.quantity = item.quantity + 1; recalc()" aria-label="One more"
                                class="w-8 h-8 flex items-center justify-center text-slate-500 hover:bg-slate-100">
                                <svg {!! $svg !!} class="w-3.5 h-3.5"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                            </button>
                        </div>
                        {{-- Price each: set here for made-to-order items, or changed for a custom job --}}
                        <label class="flex items-center gap-1 text-xs text-slate-500">
                            ₱<input type="number" step="0.01" min="0" x-model.number="item.price" @input="recalc()" placeholder="Price" aria-label="Price each"
                                class="w-20 text-right text-sm rounded-md border bg-white px-2 py-1"
                                :class="item.price > 0 ? 'border-slate-300' : 'border-amber-400 ring-1 ring-amber-300'">
                            each
                        </label>
                    </div>
                    <div class="flex items-center justify-between gap-2 mt-1.5 text-xs">
                        <span x-show="!(item.price > 0)" class="text-amber-600">Enter the price</span>
                        <span class="ml-auto text-sm font-medium text-ink" x-text="peso(lineTotal(item))"></span>
                    </div>
                    {{-- More than the store has: the whole line goes on order --}}
                    <p x-show="onOrder(item)" style="display:none;" class="mt-1.5 text-[11px] leading-snug text-amber-600" x-text="orderNote(item)"></p>
                </div>
            </template>
            <div x-show="cart.length === 0" class="text-center py-8">
                <i data-lucide="shopping-cart" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
                <p class="text-sm text-slate-400">Cart is empty. Add an item to start a sale.</p>
            </div>
        </div>

        <form action="{{ route('pos.store') }}" method="POST" @submit="handleSubmit($event)" class="p-5 space-y-3 border-t border-slate-100 bg-slate-50/50">
            @csrf
            <template x-for="(item, idx) in cart" :key="item.uid">
                <div>
                    <input type="hidden" :name="`items[${idx}][product_id]`" :value="item.product_id">
                    <input type="hidden" :name="`items[${idx}][product_variant_id]`" :value="item.variant_id ?? ''">
                    <input type="hidden" :name="`items[${idx}][quantity]`" :value="item.quantity">
                    <input type="hidden" :name="`items[${idx}][unit_price]`" :value="item.price">
                    <input type="hidden" :name="`items[${idx}][customization_details]`" :value="item.customization_details">
                    <template x-if="item.width">
                        <span>
                            <input type="hidden" :name="`items[${idx}][width]`" :value="item.width">
                            <input type="hidden" :name="`items[${idx}][height]`" :value="item.height">
                        </span>
                    </template>
                </div>
            </template>

            {{-- Customer: pick a saved one, or type a new one (saved with the sale) --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <span class="{{ $label }}">Customer</span>
                    <button type="button" @click="newCustomer = !newCustomer" class="text-[11px] text-brand-600 font-medium"
                        x-text="newCustomer ? 'Pick a saved customer' : '+ New customer'"></button>
                </div>
                <div x-show="!newCustomer">
                    <x-select-menu name="customer_id"
                        :options="collect(['' => 'Walk-in customer'])->union($customers->pluck('name', 'id'))->all()"
                        :selected="old('customer_id')"
                        placeholder="Walk-in customer"
                        onchange="customerId = value" />
                </div>
                <div x-show="newCustomer" style="display:none;" class="grid grid-cols-2 gap-2">
                    <input type="text" name="new_customer_name" x-model="newCustomerName" :disabled="!newCustomer" maxlength="150" placeholder="Name" aria-label="New customer's name" class="{{ $input }}">
                    <input type="text" name="new_customer_phone" value="{{ old('new_customer_phone') }}" :disabled="!newCustomer" maxlength="30" placeholder="Phone (optional)" aria-label="New customer's phone" class="{{ $input }}">
                </div>
            </div>

            {{-- Payment method: two choices, one tap --}}
            <div>
                <span class="{{ $label }} block mb-1">Payment</span>
                <input type="hidden" name="payment_method" :value="paymentMethod">
                <div class="grid grid-cols-2 gap-2">
                    @foreach(['cash' => 'Cash', 'gcash' => 'GCash'] as $value => $text)
                        <button type="button" @click="paymentMethod = @js($value)" :aria-pressed="paymentMethod === @js($value)"
                            class="text-sm rounded-lg border px-3 py-2"
                            :class="paymentMethod === @js($value) ? 'border-brand-500 bg-brand-50 text-brand-700 font-medium' : 'border-slate-300 bg-white text-slate-600'">
                            {{ $text }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="bg-white rounded-lg border border-slate-200 p-3 space-y-2">
                <div class="flex justify-between text-sm text-ink">
                    <span>Subtotal</span><span x-text="peso(subtotal)"></span>
                </div>
                <div class="flex justify-between items-center text-sm">
                    <span class="text-ink">Discount</span>
                    <input type="number" name="discount_amount" x-model.number="discount" step="0.01" min="0" :max="subtotal" aria-label="Discount"
                           class="w-24 text-sm rounded-lg border bg-white text-right px-2 py-1.5"
                           :class="discount > subtotal ? 'border-red-400' : 'border-slate-300'">
                </div>
                <p x-show="discount > subtotal" style="display:none;" class="text-xs text-red-600">The discount can't be more than the subtotal.</p>
                <div class="flex justify-between font-semibold text-ink text-base border-t border-slate-100 pt-2">
                    <span>Total</span><span x-text="peso(total)"></span>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label for="amount-paid" class="{{ $label }}">Amount Paid</label>
                    <div class="flex gap-2">
                        <button type="button" @click="amountPaid = Math.round(total * 0.5 * 100) / 100" class="text-[11px] text-brand-600 font-medium">50% down</button>
                        <button type="button" @click="amountPaid = total" class="text-[11px] text-brand-600 font-medium">Exact amount</button>
                    </div>
                </div>
                <input id="amount-paid" type="number" name="amount_paid" x-model.number="amountPaid" step="0.01" placeholder="0.00" min="0.01"
                       class="{{ $input }}" :class="amountPaid > 0 && amountPaid < total ? 'border-amber-400' : ''">
                <p x-show="amountPaid > 0 && amountPaid < total" style="display:none;" class="text-xs text-amber-600 mt-1">
                    Down payment: <span x-text="peso(total - amountPaid)"></span> still owed. The sale stays <strong>Pending</strong> until it's paid in full.
                    <span x-show="!hasCustomer()">Add the customer so you know who owes it.</span>
                </p>
                <p x-show="amountPaid >= total && amountPaid > 0" style="display:none;" class="text-xs text-emerald-600 mt-1">
                    Paid in full. Change: <span x-text="peso(amountPaid - total)"></span>
                </p>
            </div>

            {{-- Out of stock isn't a lost sale: the item goes on order, and the receipt says so --}}
            <div x-show="anyOnOrder()" style="display:none;"
                 class="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                <i data-lucide="truck" class="w-4 h-4 flex-shrink-0 mt-px"></i>
                <p>
                    <span class="font-medium">Marcomedia accepts orders even when items are out of stock.</span>
                    Items on order are made as soon as the stock comes in. Let the customer know, and take their number.
                </p>
            </div>
            <p x-show="cart.length > 0 && missingPrice()" style="display:none;" class="text-xs text-amber-600">Enter a price for every item in the cart.</p>
            <button type="submit" :disabled="!canSubmit()"
                class="w-full bg-ink hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg py-2.5 text-sm font-medium flex items-center justify-center gap-2">
                <i data-lucide="check-circle" class="w-4 h-4"></i>
                <span x-text="amountPaid > 0 && amountPaid < total ? 'Record Down Payment' : 'Complete Sale'"></span>
            </button>
        </form>
    </div>

    {{-- Phones: the cart is below the products. Once something is in it, this bar shows the total
         and goes to checkout; it steps aside when the cart itself is on screen. --}}
    <div x-show="cart.length > 0 && !cartInView" x-transition.opacity style="display:none;"
         class="lg:hidden fixed inset-x-0 bottom-0 z-30 bg-white border-t border-slate-200 shadow-[0_-4px_14px_rgba(15,23,42,0.08)] px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <div class="flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <p class="text-xs text-slate-500">
                    <span x-text="itemCount() + (itemCount() === 1 ? ' item' : ' items')"></span>
                    <span x-show="anyOnOrder()" class="text-amber-600"> · some on order</span>
                </p>
                <p class="text-base font-semibold text-ink" x-text="peso(total)"></p>
            </div>
            <button type="button" @click="document.getElementById('pos-cart').scrollIntoView({ behavior: 'smooth', block: 'start' })"
                class="flex-shrink-0 h-11 px-5 rounded-lg bg-ink text-white text-sm font-medium flex items-center gap-2">
                <i data-lucide="shopping-cart" class="w-4 h-4"></i> Checkout
            </button>
        </div>
    </div>
</div>

<script>
const posCatalog = @js($catalog);

function posCart() {
    // Each item's picked size or type, and the custom size typed for items cut from boards or rolls.
    const sel = {}, custom = {};
    for (const p of Object.values(posCatalog)) {
        if (p.first) sel[p.id] = Number(p.first);
        if (p.cut.length) custom[p.id] = { w: '', h: '', unit: 'in' };
    }

    return {
        search: '',
        category: '',
        sel,
        custom,
        cart: [],
        nextUid: 0,
        subtotal: 0,
        discount: @js((float) old('discount_amount', 0)),
        amountPaid: @js((float) old('amount_paid', 0)),
        paymentMethod: @js(old('payment_method', 'cash')),
        customerId: @js((string) old('customer_id', '')),   // kept in step by the customer menu's onchange
        newCustomer: @js(filled(old('new_customer_name'))),
        newCustomerName: @js(old('new_customer_name', '')),
        submitted: false,
        cartInView: false,   // phones: hides the checkout bar once the cart is on screen

        init() {
            const cartEl = document.getElementById('pos-cart');
            if (cartEl && 'IntersectionObserver' in window) {
                // The bar's own 80 px at the bottom don't count as on screen.
                new IntersectionObserver(([e]) => { this.cartInView = e.isIntersecting; }, { rootMargin: '0px 0px -80px 0px' }).observe(cartEl);
            }
            // A sale the server turned back (out of stock, a missing price...) comes back with
            // what was sent, so the cart is rebuilt instead of starting over.
            for (const line of @js(array_values(old('items', [])))) {
                const p = posCatalog[line.product_id];
                if (!p) continue;
                const v = line.product_variant_id ? p.variants[line.product_variant_id] : null;
                const fit = v && v.custom && line.width && line.height ? this.fitFor(p, line.width * 12, line.height * 12) : null;
                this.pushLine(p, line.product_variant_id || null, v, parseInt(line.quantity) || 1, parseFloat(line.unit_price) || '', line.customization_details || '', fit);
            }
            this.recalc();
            window.addEventListener('beforeunload', (e) => {
                if (this.cart.length > 0 && !this.submitted) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },

        // Search every word in the item's name, category, SKU and sizes; plus the category pill.
        visible(id) {
            const p = posCatalog[id];
            if (this.category !== '' && p.category !== this.category) return false;
            return this.search.toLowerCase().split(/\s+/).filter(Boolean).every(w => p.search.includes(w));
        },
        groupVisible(category) {
            return Object.values(posCatalog).some(p => p.category === category && this.visible(p.id));
        },
        noResults() {
            return !Object.keys(posCatalog).some(id => this.visible(id));
        },

        addItem(productId) {
            const p = posCatalog[productId];
            let variantId = null, v = null;
            const ids = Object.keys(p.variants);
            if (ids.length) {
                // One type has no picker: it's the only choice. One that's out still sells, on order.
                variantId = ids.length === 1 ? ids[0] : String(this.sel[productId]);
                v = p.variants[variantId];
                if (!v) return;
            }

            // A custom size always gets its own line, charged as the piece it fits.
            if (v && v.custom) {
                const fit = this.customFit(productId);
                if (!fit) { alert('Enter the width and height first.'); return; }
                if (!fit.fits) { alert(fit.note); return; }
                // Priced like the standard size it's charged as, if that has a price.
                const same = Object.values(p.variants).find(t => !t.custom && t.w === fit.piece.w && t.h === fit.piece.h);
                this.pushLine(p, variantId, v, 1, same && same.price > 0 ? same.price : '', '', fit);
                this.recalc();
                return;
            }

            // The same item again bumps its quantity, unless that line has its own details
            // (another design or name): then it gets a line of its own.
            const existing = this.cart.find(i => i.product_id === p.id && i.variant_id === variantId && !i.customization_details.trim());
            if (existing) {
                existing.quantity++;
            } else {
                const price = v ? v.price : p.price;
                this.pushLine(p, variantId, v, 1, price > 0 ? price : '', '');
            }
            this.recalc();
        },
        // fit: a custom size (fitFor), sent as width and height in feet.
        pushLine(p, variantId, v, quantity, price, details, fit = null) {
            this.cart.push({
                uid: ++this.nextUid,
                // A product's only type ("Standard") isn't worth showing.
                product_id: p.id, variant_id: variantId, name: p.name,
                variant_name: fit ? 'Custom ' + fit.label : (v && Object.keys(p.variants).length > 1 ? v.name : null),
                size_note: fit ? fit.note : null,
                width: fit ? fit.w / 12 : null,
                height: fit ? fit.h / 12 : null,
                // What's on hand, or what the materials can make (null = no limit). Past it, the line
                // goes on order. Lines with the same key draw on the same stock.
                max: fit && fit.piece ? Math.floor(fit.piece.available / fit.blocks) : (v && v.stock !== null ? v.stock : null),
                key: fit && fit.piece ? 'cut-' + fit.piece.id + '-' + fit.blocks : 'type-' + (variantId ?? p.id),
                price, quantity, customization_details: details,
            });
        },

        // Which cart lines go on order, as the sale will decide: in cart order, a line takes its
        // stock if what's left covers all of it, else the whole line goes on order and takes nothing.
        // left: what was left for each line.
        orderPlan() {
            const used = {}, onOrder = new Set(), left = {};
            for (const i of this.cart) {
                if (i.max === null) continue;
                const before = used[i.key] || 0, q = Number(i.quantity) || 0;
                left[i.uid] = Math.max(i.max - before, 0);
                if (before + q <= i.max) used[i.key] = before + q;
                else onOrder.add(i.uid);
            }
            return { onOrder, left };
        },
        onOrder(item) {
            return this.orderPlan().onOrder.has(item.uid);
        },
        anyOnOrder() {
            return this.cart.length > 0 && this.orderPlan().onOrder.size > 0;
        },
        orderNote(item) {
            const left = this.orderPlan().left[item.uid] || 0;
            return left > 0
                ? 'Only ' + left + ' in stock, so the whole line goes on order. Lower it to ' + left + ' to make them now.'
                : 'Out of stock: on order, made as soon as the stock comes in.';
        },
        // Why the picked size goes on order ('' if it doesn't). A custom size says so in its own note.
        outNote(productId) {
            const t = posCatalog[productId].variants[this.sel[productId]];
            if (!t || t.custom || t.stock === null || t.stock > 0) return '';
            return (t.short.length ? 'Out of ' + t.short.join(', ') : 'Out of stock') + '. You can still sell it: it goes on order.';
        },

        isCustom(productId) {
            const t = posCatalog[productId].variants[this.sel[productId]];
            return !!(t && t.custom);
        },
        // The custom size typed on an item's card, as fitFor() sees it. null until both are entered.
        customFit(productId) {
            const c = this.custom[productId];
            const per = c && c.unit === 'ft' ? 12 : 1;
            const w = c ? parseFloat(c.w) * per : 0, h = c ? parseFloat(c.h) * per : 0;
            return w > 0 && h > 0 ? this.fitFor(posCatalog[productId], w, h) : null;
        },
        // The standard piece a w x h (inches) job is charged as: on a board the smallest piece it
        // fits in, on a roll whole feet of the roll it fits across, the least used first, and one
        // the store can supply if any. The same rule as SheetYield; the sale itself decides.
        fitFor(p, w, h) {
            const eps = 0.001, options = [];
            for (const piece of p.cut) {
                let blocks = null;
                if (piece.roll) {
                    const long = Math.max(w, h), short = Math.min(w, h);
                    const along = long <= piece.w + eps ? short : (short <= piece.w + eps ? long : null);
                    if (along !== null) blocks = Math.max(1, Math.ceil(along / piece.h - eps));
                } else if ((w <= piece.w + eps && h <= piece.h + eps) || (w <= piece.h + eps && h <= piece.w + eps)) {
                    blocks = 1;
                }
                if (blocks !== null) options.push({ piece, blocks, area: blocks * piece.w * piece.h });
            }
            options.sort((a, b) => (Math.round(a.area * 1000) - Math.round(b.area * 1000)) || (a.blocks - b.blocks) || (a.piece.id - b.piece.id));

            const label = this.sizeLabel(w, h);
            if (!options.length) {
                return { ok: false, fits: false, w, h, label, note: label + ' is bigger than any ' + (p.cut.some(x => x.roll) ? 'roll' : 'board') + ' it is cut from.' };
            }
            const fit = options.find(o => o.piece.available >= o.blocks) || options[0];
            const ok = fit.piece.available >= fit.blocks;
            const charged = fit.piece.roll
                ? fit.blocks + ' ft of ' + fit.piece.board + ' (' + this.num(fit.blocks * fit.piece.width) + ' sq ft)'
                : 'Charged as ' + fit.piece.label;
            // Out of stock, it still sells: on order until the board or roll comes in.
            return { ...fit, ok, fits: true, w, h, label, note: ok ? charged : charged + '. Out of ' + fit.piece.board + ': it goes on order.' };
        },
        // "10 x 12 in", or "4.5 x 7 ft" for sizes of 2 ft and up in half feet (as SheetYield::label).
        sizeLabel(w, h) {
            const halfFeet = n => n >= 24 - 0.001 && Math.abs(n / 6 - Math.round(n / 6)) < 0.001;
            return halfFeet(w) && halfFeet(h) ? this.num(w / 12) + ' x ' + this.num(h / 12) + ' ft' : this.num(w) + ' x ' + this.num(h) + ' in';
        },
        num(n) {
            return String(Math.round(n * 100) / 100);
        },
        removeItem(idx) {
            this.cart.splice(idx, 1);
            this.recalc();
        },
        clearCart() {
            if (confirm('Remove everything from this sale?')) {
                this.cart = [];
                this.recalc();
            }
        },
        clampQuantity(item) {
            item.quantity = Math.max(parseInt(item.quantity) || 1, 1);   // past the stock is fine: it goes on order
            this.recalc();
        },

        lineTotal(item) {
            return (Number(item.price) || 0) * (Number(item.quantity) || 0);
        },
        recalc() {
            this.subtotal = this.cart.reduce((sum, i) => sum + this.lineTotal(i), 0);
        },
        get total() {
            return Math.max(this.subtotal - (Number(this.discount) || 0), 0);
        },
        itemCount() {
            return this.cart.reduce((n, i) => n + (Number(i.quantity) || 0), 0);
        },
        missingPrice() {
            return this.cart.some(i => !(i.price > 0));
        },
        hasCustomer() {
            return this.newCustomer ? this.newCustomerName.trim() !== '' : this.customerId !== '';
        },
        canSubmit() {
            return this.cart.length > 0 && this.amountPaid > 0 && !this.missingPrice() && !(this.discount > this.subtotal);
        },
        peso(n) {
            return '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        handleSubmit(e) {
            if (!this.canSubmit()) {
                e.preventDefault();
                alert(this.missingPrice() ? 'Enter a price for every item in the cart.'
                    : this.discount > this.subtotal ? "The discount can't be more than the subtotal."
                    : 'Please enter an amount paid (a full payment or a down payment) before completing the sale.');
                return;
            }
            this.submitted = true;
        },
    }
}
</script>
@endsection
