@extends('layouts.app')
@section('title', 'Packs')

@section('content')
@inject('packs', 'App\Services\PackService')
@php
    $input = 'mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    $label = 'text-xs font-medium text-slate-600 dark:text-slate-300';
    $btnCancel = 'border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2';
    $btnPrimary = 'bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-md px-4 py-2';
    $btnOutline = 'inline-flex items-center gap-1.5 text-sm border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-3 py-1.5';
@endphp

@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
        {{ $errors->first() }}
    </div>
@endif

<div x-data="packPage()">

    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <p class="text-sm text-slate-500 dark:text-slate-400 max-w-2xl">
            One pack or unit per material is in use at a time, and sales are tied to it by its ID.
            Pieces are counted as they are used. Whole units, like a roll of fabric, are removed by hand when used up.
        </p>
        <a href="{{ route('inventory.materials') }}" class="text-sm px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5 flex-shrink-0">
            <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Raw materials
        </a>
    </div>

    @if($cards->isEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-10 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">No materials yet. Add one on the Raw Materials page, then start tracking it here.</p>
            <a href="{{ route('inventory.materials') }}" class="text-brand-600 text-sm font-medium">Go to Raw Materials</a>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach($cards as $card)
        @php
            $mat = $card['material'];
            $cur = $card['current'];
            $state = $card['state'];
            $whole = $card['whole'];
            $cs = $cur ? ($stats[$cur->id] ?? null) : null;
            [$pillText, $pillClass] = [
                'ok' => [$whole ? 'In use' : 'Open', 'bg-emerald-100 text-emerald-700'],
                'low' => [$whole ? 'Storage running low' : 'Running low', 'bg-amber-100 text-amber-700'],
                'empty' => ['Empty, open a new pack', 'bg-red-100 text-red-700'],
                'none' => [$whole ? 'None in use' : 'No pack open', 'bg-red-100 text-red-700'],
                'legacy' => ['Not tracked yet', 'bg-slate-100 text-slate-600'],
            ][$state];
            $barColor = ['ok' => '#146c84', 'low' => '#d97706', 'empty' => '#ef4444', 'none' => '#ef4444', 'legacy' => '#94a3b8'][$state];
        @endphp
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium text-ink dark:text-white truncate">{{ $mat->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        @if($state === 'legacy')
                            Counted in {{ $mat->unit }}
                        @elseif($whole)
                            Whole units ({{ $mat->unit }}), removed by hand when used up
                        @else
                            Pieces ({{ $mat->unit }}), counted as they are used
                        @endif
                    </p>
                </div>
                <span class="px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
            </div>

            @if($state === 'legacy')
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                    Running total: <span class="font-medium text-ink dark:text-white">{{ $packs->fmt($mat->stock_quantity) }} {{ $mat->unit }}</span>.
                    Start tracking to give it pack IDs and tie sales to them.
                </p>
            @elseif($whole)
                <div class="mt-4 space-y-1 text-sm text-slate-500 dark:text-slate-400">
                    <p>In storage: <span class="font-medium text-ink dark:text-white">{{ $packs->fmt($mat->stock_quantity) }} {{ $mat->unit }}</span></p>
                    @if($cur)
                        <p>In use: <span class="font-medium text-ink dark:text-white">{{ $cur->code }}</span>, since {{ $cur->opened_at?->format('M j') }}</p>
                        <p>
                            Used in {{ $cs['sales'] ?? 0 }} {{ ($cs['sales'] ?? 0) === 1 ? 'sale' : 'sales' }}
                            @if(($cs['error_count'] ?? 0) > 0)
                                , <span class="text-red-600">{{ $cs['error_count'] }} {{ $cs['error_count'] === 1 ? 'error' : 'errors' }}</span>
                            @endif
                        </p>
                    @else
                        <p>None in use. Sales that need {{ $mat->name }} are blocked until you open one.</p>
                    @endif
                </div>
            @elseif($cur)
                <div class="mt-4">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <p class="font-medium text-ink dark:text-white truncate">{{ $cur->code }}</p>
                        <p class="text-slate-500 dark:text-slate-400 flex-shrink-0">{{ $packs->fmt($cur->remaining_qty) }} of {{ $packs->fmt($cur->initial_qty) }} {{ $mat->unit }} left</p>
                    </div>
                    <div class="mt-2 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden" style="height:8px;">
                        <div style="height:8px; width:{{ $card['pct'] }}%; background:{{ $barColor }};"></div>
                    </div>
                    <p class="text-xs text-slate-400 mt-1.5">
                        Opened {{ $cur->opened_at?->format('M j, Y') }}
                        @if(($cs['error_count'] ?? 0) > 0)
                            <span class="text-red-600">, {{ $packs->fmt($cs['errors']) }} {{ $mat->unit }} lost to errors</span>
                        @endif
                    </p>
                </div>
            @else
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                    No pack is open, so sales and uses that need {{ $mat->name }} are blocked until you open one.
                </p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" @click="show('open', {{ $mat->id }})"
                    class="inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5">
                    <i data-lucide="package-open" class="w-3.5 h-3.5"></i>
                    {{ $state === 'legacy' ? 'Start tracking' : ($whole ? 'Open the next one' : 'Open new pack') }}
                </button>
                @if($cur)
                    <button type="button" @click="show('usage', {{ $mat->id }})" class="{{ $btnOutline }}">Log use or error</button>
                @endif
                @if($state !== 'legacy' && ($whole || $cur))
                    <button type="button" @click="show('adjust', {{ $mat->id }})" class="{{ $btnOutline }}">
                        {{ $whole ? 'Change storage count' : 'Adjust' }}
                    </button>
                @endif
                @if($cur)
                    <button type="button" @click="show('close', {{ $mat->id }})"
                        class="inline-flex items-center gap-1.5 text-sm border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg px-3 py-1.5">
                        {{ $whole ? 'Mark used up' : 'Close pack' }}
                    </button>
                @endif
            </div>

            <datalist id="batches-{{ $mat->id }}">
                @foreach($card['batches'] as $b)
                    <option value="{{ $b->label }}">
                @endforeach
            </datalist>

            @if($card['history']->count())
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm text-brand-600">History</summary>
                    <div class="overflow-x-auto mt-2">
                        <table class="w-full text-xs">
                            @if($whole)
                                <thead>
                                    <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                                        <th class="py-2 pr-3">Unit</th><th class="pr-3">Opened</th><th class="pr-3">Closed</th>
                                        <th class="pr-3 text-right">Sales</th><th class="pr-3 text-right">Errors</th><th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($card['history'] as $p)
                                    @php $s = $stats[$p->id] ?? []; @endphp
                                    <tr class="border-b border-slate-50 dark:border-slate-700">
                                        <td class="py-2 pr-3 font-medium text-ink dark:text-white whitespace-nowrap">{{ $p->code }}</td>
                                        <td class="pr-3 whitespace-nowrap">{{ $p->opened_at?->format('M j') }}</td>
                                        <td class="pr-3 whitespace-nowrap">{{ $p->closed_at?->format('M j') ?? '-' }}</td>
                                        <td class="pr-3 text-right">{{ $s['sales'] ?? 0 }}</td>
                                        <td class="pr-3 text-right">{{ $s['error_count'] ?? 0 }}</td>
                                        <td class="text-slate-500 dark:text-slate-400">{{ $p->status === 'closed' ? 'Used up' : 'In use' }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            @else
                                <thead>
                                    <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                                        <th class="py-2 pr-3">Pack</th><th class="pr-3 text-right">Started with</th><th class="pr-3 text-right">Used</th>
                                        <th class="pr-3 text-right">Errors</th><th class="pr-3 text-right">Adjusted</th><th class="pr-3 text-right">Left over</th><th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($card['history'] as $p)
                                    @php $s = $stats[$p->id] ?? []; @endphp
                                    <tr class="border-b border-slate-50 dark:border-slate-700">
                                        <td class="py-2 pr-3 font-medium text-ink dark:text-white whitespace-nowrap">{{ $p->code }}</td>
                                        <td class="pr-3 text-right">{{ $packs->fmt($p->initial_qty) }}</td>
                                        <td class="pr-3 text-right">{{ $packs->fmt($s['used'] ?? 0) }}</td>
                                        <td class="pr-3 text-right">{{ $packs->fmt($s['errors'] ?? 0) }}</td>
                                        <td class="pr-3 text-right">{{ $packs->fmt($s['adjusted'] ?? 0) }}</td>
                                        <td class="pr-3 text-right">{{ $packs->fmt($s['leftover'] ?? 0) }}</td>
                                        <td class="capitalize text-slate-500 dark:text-slate-400">{{ $p->status }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            @endif
                        </table>
                    </div>
                </details>
            @endif
        </div>
    @endforeach
    </div>

    {{-- Used materials: everything that was opened, sold, used, rejected or removed --}}
    <div class="mt-6 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700">
            <p class="font-medium text-ink dark:text-white">Used materials</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">The last 30 changes and who made them. Errors count as used material, never as a sale amount.</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                    <th class="p-4">When</th><th>Material</th><th>Pack</th><th>What</th><th class="text-right">Amount</th><th class="text-right">Left</th><th class="pl-4">Details</th><th>By</th>
                </tr>
            </thead>
            <tbody>
            @forelse($recent as $m)
                @php
                    $badge = match($m->type) {
                        'open' => 'bg-emerald-100 text-emerald-700',
                        'sale', 'job' => 'bg-blue-100 text-blue-700',
                        'error' => 'bg-red-100 text-red-700',
                        'adjustment' => 'bg-amber-100 text-amber-700',
                        default => 'bg-slate-100 text-slate-600',
                    };
                    $reasons = ['used' => 'Used', 'broken' => 'Broken or damaged', 'miscount' => 'Miscount', 'other' => 'Other'];
                    $details = array_filter([
                        $m->sale ? 'Invoice ' . $m->sale->invoice_number : null,
                        ($m->type === 'adjustment' && $m->reason) ? ($reasons[$m->reason] ?? $m->reason) : null,
                        $m->note,
                    ]);
                @endphp
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-4 text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $m->created_at?->format('M j, h:i A') }}</td>
                    <td class="text-ink dark:text-white whitespace-nowrap">{{ $m->material->name ?? '-' }}</td>
                    <td class="font-medium text-ink dark:text-white whitespace-nowrap">{{ $m->pack->code ?? 'Storage' }}</td>
                    <td><span class="px-2 py-0.5 rounded-full text-xs whitespace-nowrap {{ $badge }}">{{ $m->type_label }}</span></td>
                    <td class="text-right font-medium whitespace-nowrap {{ $m->quantity > 0 ? 'text-emerald-600' : ($m->quantity < 0 ? 'text-red-600' : 'text-slate-400') }}">
                        @if($m->quantity == 0)
                            -
                        @else
                            {{ $m->quantity > 0 ? '+' : '' }}{{ $packs->fmt($m->quantity) }} {{ $m->material->unit ?? '' }}
                        @endif
                    </td>
                    <td class="text-right text-slate-500 dark:text-slate-400">{{ $packs->fmt($m->qty_after) }}</td>
                    <td class="pl-4 text-slate-500 dark:text-slate-400">{{ $details ? implode(', ', $details) : '-' }}</td>
                    <td class="text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $m->user->name ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-8 text-center text-sm text-slate-400">Nothing yet. Start tracking a material to begin the log.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- One overlay, four forms. Each posts to its own route; only the one matching `modal` shows. --}}
    <div x-show="modal !== null" style="display:none;" x-transition.opacity
         class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
         @click.self="hide()" @keydown.escape.window="hide()">
        <div class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6 my-8">

            {{-- Open the next pack or unit --}}
            <form x-show="modal === 'open'" style="display:none;" :action="m ? m.urls.open : ''" method="POST" class="space-y-3">
                @csrf
                <div>
                    <p class="text-lg font-semibold text-ink dark:text-white" x-text="m && m.legacy ? 'Start tracking' : (kind === 'whole' ? 'Open the next one' : 'Open a new pack')"></p>
                    <p class="text-sm text-slate-500 dark:text-slate-400" x-text="m ? m.name : ''"></p>
                </div>

                <div x-show="m && m.legacy" style="display:none;" class="space-y-2">
                    <p class="{{ $label }}">How is it counted?</p>
                    <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                        <input type="radio" name="count_type" value="piece" x-model="kind" class="mt-1">
                        <span><span class="font-medium text-ink dark:text-white">Pieces</span>, like a pack of 1,000. Each sale or use takes pieces out.</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                        <input type="radio" name="count_type" value="whole" x-model="kind" class="mt-1">
                        <span><span class="font-medium text-ink dark:text-white">Whole units</span>, like a roll of fabric. Usage can't be calculated, so you mark each one used up.</span>
                    </label>
                </div>

                <div x-show="m && m.cur_code" style="display:none;"
                     class="rounded-lg bg-amber-100 dark:bg-amber-900/30 border border-amber-400 text-amber-700 dark:text-amber-400 px-3 py-2 text-sm">
                    <span x-show="kind === 'whole'"><span x-text="m ? m.cur_code : ''"></span> will be marked used up.</span>
                    <span x-show="kind !== 'whole'">
                        <span x-text="m ? m.cur_code : ''"></span> will be closed.
                        <span x-show="m && m.cur_left > 0">The <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> still in it will be written off.</span>
                    </span>
                </div>

                <p x-show="kind === 'whole'" style="display:none;" class="text-sm text-slate-500 dark:text-slate-400">
                    Takes 1 out of storage: <span x-text="m ? m.stock : ''"></span> <span x-text="m ? m.unit : ''"></span> now,
                    <span x-text="m ? Math.max(m.stock - 1, 0) : ''"></span> after.
                </p>

                <div>
                    <label class="{{ $label }}">Batch label</label>
                    <input type="text" name="batch" x-model="batch" :list="m ? 'batches-' + m.id : null" required maxlength="60" class="{{ $input }}">
                    <p class="text-xs text-slate-400 mt-1" x-text="'IDs look like ' + (batch || 'September 2026 CB') + ' - 1, then - 2.'"></p>
                </div>
                <div x-show="kind === 'piece'">
                    <label class="{{ $label }}">How many are in the pack? (<span x-text="m ? m.unit : ''"></span>)</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" x-model="qty" :required="kind === 'piece'" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">Supplier (optional)</label>
                    <input type="text" name="supplier" x-model="supplier" maxlength="100" class="{{ $input }}">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}" x-text="kind === 'whole' ? 'Open it' : 'Open pack'"></button>
                </div>
            </form>

            {{-- Adjust: the open pack for pieces, the storage count for whole units --}}
            <form x-show="modal === 'adjust'" style="display:none;" :action="m ? m.urls.adjust : ''" method="POST" class="space-y-3">
                @csrf
                <div>
                    <p class="text-lg font-semibold text-ink dark:text-white" x-text="kind === 'whole' ? 'Change the storage count' : 'Adjust the count'"></p>
                    <p class="text-sm text-slate-500 dark:text-slate-400" x-show="kind === 'whole'">
                        <span x-text="m ? m.stock : ''"></span> <span x-text="m ? m.unit : ''"></span> in storage now.
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400" x-show="kind !== 'whole'">
                        <span x-text="m ? m.cur_code : ''"></span> has <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> left.
                    </p>
                </div>
                <div>
                    <label class="{{ $label }}">What happened</label>
                    <select name="reason" x-model="reason" class="{{ $input }}">
                        <option value="used">Used (removed by hand)</option>
                        <option value="broken">Broken or damaged</option>
                        <option value="miscount">Miscount</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $label }}">Direction</label>
                        <select name="direction" x-model="dir" class="{{ $input }}">
                            <option value="out">Take out</option>
                            <option value="in">Add back</option>
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Amount (<span x-text="m ? m.unit : ''"></span>)</label>
                        <input type="number" step="0.01" min="0.01" name="quantity" x-model="qty" required class="{{ $input }}">
                    </div>
                </div>
                <div>
                    <label class="{{ $label }}">Note (optional)</label>
                    <input type="text" name="note" x-model="note" maxlength="255" class="{{ $input }}">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Save</button>
                </div>
            </form>

            {{-- Log a use or an error against the pack or unit in use --}}
            <form x-show="modal === 'usage'" style="display:none;" :action="m ? m.urls.usage : ''" method="POST" class="space-y-3">
                @csrf
                <div>
                    <p class="text-lg font-semibold text-ink dark:text-white">Log use or error</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400" x-show="kind === 'whole'">
                        Tied to <span x-text="m ? m.cur_code : ''"></span>, the one in use. Nothing is subtracted.
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400" x-show="kind !== 'whole'">
                        Comes out of <span x-text="m ? m.cur_code : ''"></span>, which has <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> left.
                    </p>
                </div>
                <div>
                    <label class="{{ $label }}">What happened</label>
                    <select name="use_type" x-model="useType" class="{{ $input }}">
                        <option value="job">Used for a job</option>
                        <option value="error">Error, rejected (counts as used, not as a sale amount)</option>
                    </select>
                </div>
                <div x-show="kind !== 'whole'">
                    <label class="{{ $label }}">How many (<span x-text="m ? m.unit : ''"></span>)</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" x-model="qty" :required="kind !== 'whole'" class="{{ $input }}">
                    <p x-show="tooMuch()" style="display:none;" class="text-xs text-amber-600 mt-1">That is more than what is left in this pack.</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $label }}">Invoice no. (optional)</label>
                        <input type="text" name="invoice" x-model="invoice" maxlength="30" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="{{ $label }}">Note (optional)</label>
                        <input type="text" name="note" x-model="note" maxlength="255" class="{{ $input }}">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                    <button type="submit" class="{{ $btnPrimary }}">Save</button>
                </div>
            </form>

            {{-- Close the pack, or mark the whole unit used up --}}
            <form x-show="modal === 'close'" style="display:none;" :action="m ? m.urls.close : ''" method="POST" class="space-y-3">
                @csrf
                <p class="text-lg font-semibold text-ink dark:text-white" x-text="kind === 'whole' ? 'Mark it used up?' : 'Close this pack?'"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400" x-show="kind === 'whole'">
                    <span class="font-medium text-ink dark:text-white" x-text="m ? m.cur_code : ''"></span> moves to used materials.
                    Sales that need <span x-text="m ? m.name : ''"></span> are blocked until you open the next one.
                </p>
                <p class="text-sm text-slate-500 dark:text-slate-400" x-show="kind !== 'whole'">
                    <span class="font-medium text-ink dark:text-white" x-text="m ? m.cur_code : ''"></span> will be closed<span x-show="m && m.cur_left > 0">, and the <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> still in it written off</span>.
                    Sales and uses that need <span x-text="m ? m.name : ''"></span> are blocked until you open a new pack.
                </p>
                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
                    <button type="button" @click="hide()" class="{{ $btnCancel }}">Keep it</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2" x-text="kind === 'whole' ? 'Mark used up' : 'Close pack'"></button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
const packData = @js($payloads);

function packPage() {
    return {
        m: null,
        modal: null,
        kind: 'piece',
        batch: '', qty: '', supplier: '',
        dir: 'out', reason: 'broken', note: '',
        useType: 'job', invoice: '',

        show(modal, id) {
            const mat = packData[id];
            this.m = mat;
            this.modal = modal;
            this.kind = mat.count_type;
            this.batch = mat.suggest;
            this.qty = (modal === 'open' && mat.legacy && mat.stock > 0) ? mat.stock : '';
            this.supplier = '';
            this.dir = 'out';
            this.reason = mat.count_type === 'whole' ? 'used' : 'broken';
            this.note = '';
            this.useType = 'job';
            this.invoice = '';
        },

        hide() { this.modal = null; },

        tooMuch() {
            return !!this.m && this.kind !== 'whole' && parseFloat(this.qty) > this.m.cur_left;
        },
    };
}
</script>
@endsection
