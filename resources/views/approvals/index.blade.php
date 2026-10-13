@extends('layouts.app')
@section('title', $owner ? 'Approvals' : 'My Requests')

@section('content')
@php
    $icon = ['product.create' => 'package-plus', 'product.update' => 'pencil', 'sale.void' => 'ban'];
    $tone = ['product.create' => 'bg-brand-50 text-brand-700', 'product.update' => 'bg-blue-50 text-blue-700', 'sale.void' => 'bg-red-50 text-red-700'];
    $money = fn ($n) => $n === null || $n === '' ? null : '₱' . number_format((float) $n, 2);
    $answer = [
        'approved' => ['Approved', 'bg-emerald-100 text-emerald-700'],
        'rejected' => ['Rejected', 'bg-red-100 text-red-700'],
        'cancelled' => ['Cancelled', 'bg-slate-100 text-slate-600'],
    ];
@endphp
<div class="max-w-4xl space-y-6">
    <p class="text-sm text-slate-500">
        @if($owner)
            What cashiers asked to change. Nothing changes until you approve it: adding or changing a product, and voiding a sale.
        @else
            What you asked the owner to do. Adding or changing a product and voiding a sale need an owner's approval; nothing changes until then.
        @endif
    </p>

    {{-- ============ Waiting ============ --}}
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Waiting for {{ $owner ? 'you' : 'the owner' }} ({{ $pending->count() }})</p>

        @forelse($pending as $item)
            @php $r = $item['request']; @endphp
            <div class="bg-white rounded-xl border border-slate-200 mb-4 overflow-hidden" x-data="{ rejecting: false }">
                <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4 border-b border-slate-100">
                    <div class="flex items-start gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 {{ $tone[$r->type] ?? 'bg-slate-100 text-slate-600' }}">
                            <i data-lucide="{{ $icon[$r->type] ?? 'help-circle' }}" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-ink">{{ $r->summary }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $r->type_label }} · asked by {{ $r->requester->name ?? 'someone' }} · {{ $r->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                    <span class="px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700 flex-shrink-0">Waiting</span>
                </div>

                <div class="px-5 py-4 text-sm">
                    @if($r->type === 'product.create')
                        @php $p = $item['product']; @endphp
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                            <div class="flex gap-2"><dt class="text-slate-500 w-24 flex-shrink-0">Name</dt><dd class="text-ink font-medium">{{ $p['name'] }}</dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 w-24 flex-shrink-0">SKU</dt><dd class="text-ink">{{ $p['sku'] }}</dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 w-24 flex-shrink-0">Category</dt><dd class="text-ink">{{ $p['category'] }}</dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 w-24 flex-shrink-0">Base price</dt><dd class="text-ink">{{ $p['base_price'] > 0 ? $money($p['base_price']) : 'At the counter' }}</dd></div>
                            <div class="flex gap-2"><dt class="text-slate-500 w-24 flex-shrink-0">Stock count</dt><dd class="text-ink">{{ $p['track'] ? 'Kept (starts at 0)' : 'No, made to order' }}</dd></div>
                            @if($p['description'])
                                <div class="flex gap-2 sm:col-span-2"><dt class="text-slate-500 w-24 flex-shrink-0">Description</dt><dd class="text-ink">{{ $p['description'] }}</dd></div>
                            @endif
                        </dl>
                        @if($p['sizes'])
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead><tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                                        <th class="py-1.5 pr-3 font-medium">Size</th><th class="py-1.5 pr-3 font-medium">SKU</th>
                                        <th class="py-1.5 pr-3 font-medium text-right">Price</th><th class="py-1.5 font-medium">Made from</th>
                                    </tr></thead>
                                    <tbody>
                                    @foreach($p['sizes'] as $size)
                                        <tr class="border-b border-slate-50">
                                            <td class="py-1.5 pr-3 text-ink">{{ $size['name'] }}</td>
                                            <td class="py-1.5 pr-3 text-slate-500">{{ $size['sku'] }}</td>
                                            <td class="py-1.5 pr-3 text-right whitespace-nowrap">{{ $money($size['price']) ?? 'Base price' }}</td>
                                            <td class="py-1.5 text-slate-600">{{ $size['made_from'] ?: '-' }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="mt-3 text-slate-500">No sizes: sold as one item.</p>
                        @endif

                    @elseif($r->type === 'product.update')
                        <p class="text-slate-500 mb-2">
                            Changes to
                            @if($r->product)
                                <a href="{{ route('inventory.index', ['search' => $r->product->name]) }}" class="font-medium text-ink hover:underline">{{ $r->product->name }}</a>:
                            @else
                                a product that no longer exists:
                            @endif
                        </p>
                        @if(! empty($item['changes']))
                            <ul class="space-y-1">
                                @foreach($item['changes'] as $line)
                                    <li class="flex gap-2"><i data-lucide="arrow-right" class="w-3.5 h-3.5 text-slate-400 mt-1 flex-shrink-0"></i><span class="text-ink">{{ $line }}</span></li>
                                @endforeach
                            </ul>
                        @elseif($r->product)
                            <p class="text-slate-500">It now matches the product as it is: approving changes nothing.</p>
                        @endif
                        @if(! empty($item['note']))
                            <p class="mt-3 text-xs text-slate-500">{{ $item['note'] }}</p>
                        @endif

                    @elseif($r->type === 'sale.void')
                        @if($sale = $r->sale)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                                <div class="flex gap-2"><span class="text-slate-500 w-24 flex-shrink-0">Invoice</span><span class="text-ink font-medium">{{ $sale->invoice_number }}</span></div>
                                <div class="flex gap-2"><span class="text-slate-500 w-24 flex-shrink-0">Customer</span><span class="text-ink">{{ $sale->customer->name ?? 'Walk-in' }}</span></div>
                                <div class="flex gap-2"><span class="text-slate-500 w-24 flex-shrink-0">Total</span><span class="text-ink">{{ $money($sale->total_amount) }} <span class="text-slate-500">({{ $money($sale->amount_paid) }} paid)</span></span></div>
                                <div class="flex gap-2"><span class="text-slate-500 w-24 flex-shrink-0">Status</span><span class="text-ink capitalize">{{ $sale->status }}</span></div>
                                <div class="flex gap-2"><span class="text-slate-500 w-24 flex-shrink-0">Rung up by</span><span class="text-ink">{{ $sale->user->name ?? '-' }}, {{ $sale->created_at->format('M j, h:i A') }}</span></div>
                                <div class="flex gap-2 sm:col-span-2"><span class="text-slate-500 w-24 flex-shrink-0">Items</span>
                                    <span class="text-ink">{{ $sale->items->map(fn ($i) => $i->item_name . ($i->variant_name ? " ({$i->variant_name})" : '') . ' x' . $i->quantity)->implode(', ') }}</span></div>
                            </div>
                            <p class="mt-3 text-xs text-slate-500">Voiding gives back the stock and materials it took.</p>
                        @endif
                    @endif

                    @if($r->reason)
                        <div class="mt-4 rounded-lg bg-slate-50 px-3 py-2">
                            <p class="text-xs text-slate-500">Why, from {{ $r->requester->name ?? 'the cashier' }}</p>
                            <p class="text-ink">{{ $r->reason }}</p>
                        </div>
                    @endif

                    @if($item['blocked'])
                        <p class="mt-4 rounded-lg bg-amber-50 border border-amber-300 text-amber-800 px-3 py-2 text-sm">{{ $item['blocked'] }}</p>
                    @endif
                </div>

                <div class="px-5 py-3 bg-slate-50 border-t border-slate-100">
                    @if($owner)
                        <div x-show="!rejecting" class="flex flex-wrap items-center gap-2">
                            @unless($item['blocked'])
                                <form method="POST" action="{{ route('approvals.approve', $r) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-1.5 text-sm font-medium bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2">
                                        <i data-lucide="check" class="w-4 h-4"></i> Approve
                                    </button>
                                </form>
                            @endunless
                            <button type="button" @click="rejecting = true; $nextTick(() => $refs.note.focus())"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium border border-red-200 text-red-600 bg-white hover:bg-red-50 rounded-lg px-4 py-2">
                                <i data-lucide="x" class="w-4 h-4"></i> Reject
                            </button>
                        </div>
                        <form x-show="rejecting" style="display:none;" method="POST" action="{{ route('approvals.reject', $r) }}" class="space-y-2">
                            @csrf
                            <label class="block text-xs font-medium text-slate-600">Tell {{ $r->requester->name ?? 'them' }} why (optional)</label>
                            <input x-ref="note" type="text" name="note" maxlength="500" placeholder="e.g. the price is wrong, check with me first"
                                   class="w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                            <div class="flex gap-2">
                                <button type="submit" class="text-sm font-medium bg-red-600 hover:bg-red-700 text-white rounded-lg px-4 py-2">Reject</button>
                                <button type="button" @click="rejecting = false" class="text-sm text-slate-500 px-3">Cancel</button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-slate-500">The owner will approve or reject it. You'll see the answer here.</p>
                            <form method="POST" action="{{ route('approvals.cancel', $r) }}" onsubmit="return confirm('Cancel this request?')">
                                @csrf
                                <button type="submit" class="text-sm text-slate-600 border border-slate-300 bg-white hover:bg-slate-50 rounded-lg px-3 py-1.5">Cancel request</button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
                <i data-lucide="check-circle-2" class="w-8 h-8 text-emerald-500 mx-auto mb-2"></i>
                <p class="text-sm text-slate-500">{{ $owner ? 'Nothing is waiting for your approval.' : "You're not waiting on anything." }}</p>
            </div>
        @endforelse
    </div>

    {{-- ============ Answered ============ --}}
    @if($decided->isNotEmpty())
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">Answered lately</p>
        <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="p-4 font-medium">Request</th>
                    @if($owner)<th class="px-2 font-medium">Asked by</th>@endif
                    <th class="px-2 font-medium">Answer</th><th class="px-2 font-medium">Note</th><th class="pr-4 font-medium">When</th>
                </tr></thead>
                <tbody>
                @foreach($decided as $r)
                    @php [$label, $class] = $answer[$r->status] ?? [ucfirst($r->status), 'bg-slate-100 text-slate-600']; @endphp
                    <tr class="border-b border-slate-50 {{ ! $owner && $r->requester_seen_at === null ? 'bg-brand-50/50' : '' }}">
                        <td class="p-4 text-ink">{{ $r->summary }}</td>
                        @if($owner)<td class="px-2 text-slate-600">{{ $r->requester->name ?? '-' }}</td>@endif
                        <td class="px-2 whitespace-nowrap">
                            <span class="px-2 py-1 rounded-full text-xs font-medium {{ $class }}">{{ $label }}</span>
                            @if($r->decider && $r->status !== 'cancelled')<span class="block mt-1 text-xs text-slate-400">by {{ $r->decider->name }}</span>@endif
                        </td>
                        <td class="px-2 text-slate-600">{{ $r->decision_note ?? '-' }}</td>
                        <td class="pr-4 whitespace-nowrap text-slate-500">{{ optional($r->decided_at)->format('M j, h:i A') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
