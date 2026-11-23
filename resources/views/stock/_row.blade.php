{{-- One material on the Continuous or Discrete page, as a row of the list. Needs $card and $kind.
     The row shows the everyday buttons; More opens the rest underneath (packs, sizes, history).
     Plain words throughout: a batch is a "pack", the open one the "pack in use". --}}
@php
    $fmt = fn ($n) => \App\Services\Inventory\StockAlertService::formatQty($n);
    $m = $card['m'];
    $active = $card['active'];
    $store = $card['sealedStore'];
    $warehouse = $card['sealedWarehouse'];
    [$pillText, $pillClass] = [
        'ok' => ['In stock', 'bg-emerald-100 text-emerald-700'],
        'low' => ['Running low', 'bg-amber-100 text-amber-700'],
        'out' => ['Out of stock', 'bg-red-100 text-red-700'],
    ][$card['state']];
    $barColor = ['ok' => '#146c84', 'low' => '#d97706', 'out' => '#ef4444'][$card['state']];
    // Stock bar: full at twice the reorder level, with a tick where reordering starts.
    $reorder = (float) $m->low_stock_threshold;
    $scale = max($card['stock'], $reorder * 2);
    $stockPct = $scale > 0 ? (int) round(min(1, max(0, $card['stock']) / $scale) * 100) : 0;
    $reorderPct = $scale > 0 && $reorder > 0 ? (int) round($reorder / $scale * 100) : null;
    $btnOutline = 'inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-1.5';
    $btnPrimary = 'inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5';
    $otherKind = $kind === 'continuous' ? 'discrete' : 'continuous';
    // Boards and rolls (sintra board, tarpaulin): counted whole, and cut whole into standard pieces.
    $sheet = $card['sheet'];
    $isSheet = $sheet !== null;
    $noun = $isSheet ? $m->sheetNoun() : 'pack';
    $nouns = fn ($n) => $noun . ($n == 1 ? '' : 's');
    $canCut = $isSheet && ! $m->isRoll() && $sheet['cuts']->isNotEmpty();
@endphp
<div x-data="{ open: false }" x-show="shown({{ $m->id }})" class="border-b border-slate-100 last:border-b-0">
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,2fr)_7.5rem_minmax(0,1.4fr)_minmax(0,1.4fr)_21rem] gap-x-4 gap-y-3 items-center px-4 py-3">
        {{-- Material --}}
        <div class="min-w-0 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="font-medium text-ink truncate">{{ $m->name }}</p>
                <p class="text-xs text-slate-500 truncate">
                    @if($isSheet)
                        {{ $m->sheetLabel() }} {{ $nouns(2) }} · Code {{ $m->code ?? '-' }}
                    @else
                        {{ $kind === 'continuous' ? 'Measured' : 'Counted' }} in {{ $m->unit }} · Code {{ $m->code ?? '-' }}
                    @endif
                </p>
            </div>
            <span class="lg:hidden px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
        </div>

        {{-- Status --}}
        <div class="hidden lg:block">
            <span class="px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap {{ $pillClass }}">{{ $pillText }}</span>
        </div>

        {{-- In stock, with the bar against the reorder level --}}
        <div class="min-w-0">
            <p class="text-sm text-ink">
                @if($isSheet)
                    <span class="font-semibold">{{ $sheet['whole'] }}</span> <span class="text-slate-500">whole {{ $nouns($sheet['whole']) }}</span>
                @else
                    <span class="font-semibold">{{ $fmt($m->stock_quantity) }}</span> <span class="text-slate-500">{{ $m->unit }}</span>
                @endif
            </p>
            <div class="relative mt-1.5 rounded-full bg-slate-100" style="height:6px;"
                 title="{{ $reorder > 0 ? 'The line marks when to buy more' : 'No reorder level set' }}">
                <div class="rounded-full" style="height:6px; width:{{ $stockPct }}%; background:{{ $barColor }};"></div>
                @if($reorderPct !== null)
                    <div class="absolute bg-slate-500" style="top:-3px; height:12px; width:2px; left:{{ $reorderPct }}%;"></div>
                @endif
            </div>
            <p class="text-[11px] text-slate-400 mt-1 truncate">
                @if($isSheet)
                    {{ $sheet['warehouse'] ? $sheet['warehouse'] . ' more in the warehouse' : 'All in the store' }}
                @elseif($store->count() || $warehouse->count())
                    Unopened: {{ $store->count() }} in store{{ $warehouse->count() ? ', ' . $warehouse->count() . ' in warehouse' : '' }}
                @else
                    No unopened packs
                @endif
            </p>
        </div>

        {{-- Pack in use (boards: pieces ready; rolls: feet measured off) --}}
        <div class="min-w-0">
            @if($isSheet && $m->isRoll())
                <p class="text-xs text-slate-500">Already measured off</p>
                <p class="text-sm text-ink"><span class="font-semibold">{{ $fmt($sheet['ready']) }}</span> <span class="text-slate-500">ft</span></p>
            @elseif($isSheet)
                <p class="text-xs text-slate-500">Pieces ready to sell</p>
                @php $ready = $sheet['cuts']->filter(fn ($c) => $c['ready'] > 0); @endphp
                <p class="text-sm text-ink truncate" title="{{ $ready->map(fn ($c) => $fmt($c['ready']) . ' ' . $c['piece']->cutLabel())->implode(', ') }}">
                    {{ $ready->isNotEmpty() ? $ready->map(fn ($c) => $fmt($c['ready']) . ' ' . preg_replace('/\s*\(.*\)$/', '', $c['piece']->cutLabel()))->implode(' · ') : 'None cut yet' }}
                </p>
            @elseif($active)
                <p class="text-xs text-slate-500">Pack in use</p>
                <p class="text-sm text-ink">{{ $fmt($active->remaining_quantity) }} of {{ $fmt($active->opening_quantity) }} {{ $m->unit }} left</p>
                <div class="mt-1.5 rounded-full bg-slate-100 overflow-hidden" style="height:6px;">
                    <div style="height:6px; width:{{ $card['pct'] }}%; background:{{ $barColor }};"></div>
                </div>
            @else
                <p class="text-xs text-slate-500">Pack in use</p>
                <p class="text-sm text-slate-500">{{ $store->count() ? 'None open yet' : 'Nothing in stock' }}</p>
            @endif
        </div>

        {{-- Everyday buttons; More opens the rest --}}
        <div class="flex flex-wrap lg:flex-nowrap items-center gap-2 lg:justify-end">
            @if($kind === 'continuous' && ! $isSheet)
                <button type="button" @click="show('pull', {{ $m->id }})" title="Type how much you used for a job. It comes off the pack in use." class="{{ $btnPrimary }}">
                    <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Record use
                </button>
            @elseif($canCut)
                <button type="button" @click="show('cutboards', {{ $m->id }})" title="Cut whole boards into ready pieces" class="{{ $btnPrimary }}">
                    <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Cut boards
                </button>
            @endif
            <button type="button" @click="show('restock', {{ $m->id }})" title="New stock arrived: add it"
                    class="{{ ($kind === 'continuous' && ! $isSheet) || $canCut ? $btnOutline : $btnPrimary }}">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add stock
            </button>
            <button type="button" @click="open = !open" :aria-expanded="open" aria-controls="more-{{ $m->id }}"
                    title="Damage, fix count, packs, sizes and history" class="{{ $btnOutline }}">
                More <i data-lucide="chevron-down" class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''"></i>
            </button>
        </div>
    </div>

    {{-- Opened with More --}}
    <div id="more-{{ $m->id }}" x-show="open" style="display:none;" class="px-4 pb-4">
        <div class="rounded-lg bg-slate-50 p-4 space-y-4">
            @if($isSheet && $sheet['part_used'])
                <p class="text-xs text-amber-600" title="{{ $sheet['part_used']->batch_number }}">
                    1 part-used {{ $noun }} from before: {{ $fmt($sheet['part_used']->remaining_quantity) }} sq ft left. Report it with Damaged {{ $noun }}.
                </p>
            @endif
            @if($isSheet && $m->isRoll())
                <p class="text-xs text-slate-500">Each roll is {{ $sheet['cuts']->first()['piece']->cut_yield ?? 0 }} ft long, {{ $fmt($m->sheet_width) }} ft wide. Sales take it by the foot.</p>
            @elseif($isSheet)
                <p class="text-xs text-slate-500">When a size runs out, a sale cuts a new board by itself.</p>
            @elseif($active)
                <p class="text-xs text-slate-500">Pack code in use: <span class="text-ink">{{ $active->batch_number }}</span></p>
            @elseif($store->count())
                <p class="text-xs text-slate-500">The next pack opens by itself when it's used.</p>
            @endif

            {{-- The board's standard sizes: how many pieces one board gives (the fixed yield) --}}
            @if($isSheet && ! $m->isRoll())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
                            <th class="py-1.5 pr-2 font-medium">Size</th>
                            <th class="py-1.5 px-2 font-medium text-right">Pieces per board</th>
                            <th class="py-1.5 px-2 font-medium text-right">Ready</th>
                            <th class="py-1.5 pl-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sheet['cuts'] as $cut)
                        <tr class="border-b border-slate-100">
                            <td class="py-1.5 pr-2 text-ink">{{ $cut['piece']->cutLabel() }}</td>
                            <td class="py-1.5 px-2 text-right whitespace-nowrap" title="{{ $cut['trim'] }}% of each board is left over as trim (paid for in the pieces' cost)">
                                {{ $cut['piece']->cut_yield }}
                            </td>
                            <td class="py-1.5 px-2 text-right {{ $cut['ready'] > 0 ? 'text-ink font-medium' : 'text-slate-400' }}">{{ $fmt($cut['ready']) }}</td>
                            <td class="py-1.5 pl-2 whitespace-nowrap text-right">
                                <button type="button" @click="show('cutboards', {{ $m->id }}, {{ $cut['piece']->id }})" title="Cut whole boards into this size" class="text-xs text-brand-600 hover:underline">Cut</button>
                                {{-- A misprint: record it on the Rejected page, with this size filled in --}}
                                <a href="{{ route('stock.rejected', ['record' => $cut['piece']->id]) }}" title="A piece of this size was misprinted or spoiled" class="text-xs text-slate-500 hover:underline ml-2">Mistake</a>
                                <button type="button" @click="show('removesize', {{ $m->id }}, {{ $cut['piece']->id }})" title="Stop cutting this size" aria-label="Remove {{ $cut['piece']->cutLabel() }}"
                                    class="ml-1.5 text-slate-400 hover:text-red-600 align-middle"><i data-lucide="x" class="w-3.5 h-3.5 inline"></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-xs text-slate-500">No sizes yet. Press Add a size for each size this board is cut into.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @endif

            <div class="flex flex-wrap gap-2">
                @if($isSheet)
                    @if($sheet['warehouse'])
                        <button type="button" @click="show('transfer', {{ $m->id }})" title="Move one from the warehouse to the store, ready to use" class="{{ $btnOutline }}">
                            <i data-lucide="truck" class="w-3.5 h-3.5"></i> Bring one from the warehouse
                        </button>
                    @endif
                    @if($sheet['whole'] || $sheet['part_used'])
                        <button type="button" @click="show('writeoff', {{ $m->id }})" title="A whole {{ $noun }} is broken or spoiled: take it off the stock" class="{{ $btnOutline }}">
                            <i data-lucide="triangle-alert" class="w-3.5 h-3.5"></i> Damaged {{ $noun }}
                        </button>
                    @endif
                    @if(! $m->isRoll())
                        <button type="button" @click="show('addsize', {{ $m->id }})" title="Add a size this board is cut into" class="{{ $btnOutline }}">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add a size
                        </button>
                    @endif
                @else
                    @if($active)
                        <button type="button" @click="show('loss', {{ $m->id }})" title="Some got damaged or wasted: take it off the stock" class="{{ $btnOutline }}">
                            <i data-lucide="triangle-alert" class="w-3.5 h-3.5"></i> Report damage
                        </button>
                        <button type="button" @click="show('count', {{ $m->id }})" title="The number is wrong: type what's really there" class="{{ $btnOutline }}">
                            <i data-lucide="calculator" class="w-3.5 h-3.5"></i> Fix count
                        </button>
                    @endif
                    {{-- Counted pieces left in the pack in use are used first (the next pack opens by itself),
                         so it's offered only while there are none: starting it threw the rest away --}}
                    @if($store->count() && ($kind === 'continuous' || ! $active || (float) $active->remaining_quantity <= 0.0005))
                        <button type="button" @click="show('open', {{ $m->id }})" title="Start using the next unopened pack. The one in use now is marked finished." class="{{ $btnOutline }}">
                            <i data-lucide="package-open" class="w-3.5 h-3.5"></i> Start a new pack
                        </button>
                    @endif
                    @if($warehouse->count())
                        <button type="button" @click="show('transfer', {{ $m->id }})" title="Move the oldest unopened pack from the warehouse to the store" class="{{ $btnOutline }}">
                            <i data-lucide="truck" class="w-3.5 h-3.5"></i> Bring a pack from the warehouse
                        </button>
                    @endif
                @endif
                {{-- Rarely used: moving it to the other page (boards stay Discrete, rolls Continuous), archiving --}}
                <div class="flex flex-wrap gap-2 sm:ml-auto">
                    @unless($isSheet)
                    <form method="POST" action="{{ route('stock.type', $m) }}">
                        @csrf
                        <input type="hidden" name="inventory_type" value="{{ $otherKind }}">
                        <button type="submit" class="{{ $btnOutline }}"
                                title="{{ $otherKind === 'discrete' ? "If it's counted one by one, not measured" : "If it's measured, not counted one by one" }}">
                            <i data-lucide="arrow-left-right" class="w-3.5 h-3.5"></i> Move to {{ ucfirst($otherKind) }}
                        </button>
                    </form>
                    @endunless
                    <button type="button" @click="show('archive', {{ $m->id }})" title="No longer used: hide it from this page"
                            class="inline-flex items-center gap-1.5 text-sm border border-red-200 text-red-600 hover:bg-red-50 rounded-lg px-3 py-1.5">
                        <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive
                    </button>
                </div>
            </div>

            @if($card['history']->count())
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">History</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-slate-500 border-b border-slate-200">
                                    <th class="py-2 pr-3">When</th><th class="pr-3">Pack</th><th class="pr-3">What</th>
                                    <th class="pr-3 text-right">Amount</th><th class="pr-3">Details</th><th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($card['history'] as $e)
                                <tr class="border-b border-slate-100">
                                    <td class="py-2 pr-3 whitespace-nowrap text-slate-500">{{ optional($e['at'])->format('M j, h:i A') }}</td>
                                    <td class="pr-3 whitespace-nowrap font-medium text-ink">{{ $e['batch'] }}</td>
                                    <td class="pr-3 whitespace-nowrap">{{ $e['what'] }}</td>
                                    <td class="pr-3 text-right whitespace-nowrap {{ $e['qty'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $e['qty'] >= 0 ? '+' : '' }}{{ $fmt($e['qty']) }}@isset($e['unit']) <span class="text-slate-400">{{ $e['unit'] }}</span>@endisset</td>
                                    <td class="pr-3 text-slate-500">{{ $e['detail'] ?? '-' }}</td>
                                    <td class="whitespace-nowrap text-slate-500">{{ $e['by'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
