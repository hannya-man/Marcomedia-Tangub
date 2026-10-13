{{-- One material on the Continuous or Discrete page. Needs $card and $kind. Plain words throughout:
     a batch is a "pack", the open one the "pack in use"; every button says what it does on hover. --}}
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
    $btnOutline = 'inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-1.5';
    $btnPrimary = 'inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5';
    $menuItem = 'w-full flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-left';
    $otherKind = $kind === 'continuous' ? 'discrete' : 'continuous';
    // Boards and rolls (sintra board, tarpaulin): counted whole, and cut whole into standard pieces.
    $sheet = $card['sheet'];
    $isSheet = $sheet !== null;
    $noun = $isSheet ? $m->sheetNoun() : 'pack';
    $nouns = fn ($n) => $noun . ($n == 1 ? '' : 's');
@endphp
<div x-data="{ history: false, more: false }" x-show="shown({{ $m->id }})" class="bg-white rounded-xl border border-slate-200 p-5 flex flex-col">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-medium text-ink truncate">{{ $m->name }}</p>
            <p class="text-xs text-slate-500">
                @if($isSheet)
                    {{ $m->sheetLabel() }} {{ $nouns(2) }} · Code {{ $m->code ?? '-' }}
                @else
                    {{ $kind === 'continuous' ? 'Measured' : 'Counted' }} in {{ $m->unit }} · Code {{ $m->code ?? '-' }}
                @endif
            </p>
        </div>
        <span class="px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
    </div>

    @if($isSheet)
    <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-lg bg-slate-50 p-3">
            <p class="text-xs text-slate-500">Whole {{ $nouns(2) }}</p>
            <p class="text-xl font-semibold text-ink">
                {{ $sheet['whole'] }} <span class="text-sm font-normal text-slate-500">{{ $nouns($sheet['whole']) }}</span>
            </p>
            <p class="text-xs text-slate-500">
                In the store{{ $sheet['warehouse'] ? ', and ' . $sheet['warehouse'] . ' more in the warehouse' : '' }}
            </p>
            @if($sheet['part_used'])
                <p class="text-xs text-amber-600 mt-0.5" title="{{ $sheet['part_used']->batch_number }}">
                    1 part-used {{ $noun }} from before: {{ $fmt($sheet['part_used']->remaining_quantity) }} sq ft left. Report it with Damaged {{ $noun }}.
                </p>
            @endif
        </div>
        <div class="rounded-lg bg-slate-50 p-3 min-w-0">
            @if($m->isRoll())
                <p class="text-xs text-slate-500">Already measured off</p>
                <p class="text-xl font-semibold text-ink">{{ $fmt($sheet['ready']) }} <span class="text-sm font-normal text-slate-500">ft</span></p>
                <p class="text-xs text-slate-500">Each roll is {{ $sheet['cuts']->first()['piece']->cut_yield ?? 0 }} ft long, {{ $fmt($m->sheet_width) }} ft wide. Sales take it by the foot.</p>
            @else
                <p class="text-xs text-slate-500">Pieces ready to sell</p>
                @php $ready = $sheet['cuts']->filter(fn ($c) => $c['ready'] > 0); @endphp
                @if($ready->isNotEmpty())
                    <p class="text-sm font-medium text-ink">
                        {{ $ready->map(fn ($c) => $fmt($c['ready']) . ' ' . preg_replace('/\s*\(.*\)$/', '', $c['piece']->cutLabel()))->implode(' · ') }}
                    </p>
                @else
                    <p class="text-sm text-slate-500">None cut yet</p>
                @endif
                <p class="text-xs text-slate-500">When a size runs out, a sale cuts a new board by itself.</p>
            @endif
        </div>
    </div>

    @if(! $m->isRoll())
    {{-- The board's standard sizes: how many pieces one board gives (the fixed yield) --}}
    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                    <th class="py-1.5 pr-2 font-medium">Size</th>
                    <th class="py-1.5 px-2 font-medium text-right">Pieces per board</th>
                    <th class="py-1.5 px-2 font-medium text-right">Ready</th>
                    <th class="py-1.5 pl-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($sheet['cuts'] as $cut)
                <tr class="border-b border-slate-50">
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

    <div class="mt-4 flex flex-wrap gap-2">
        @if(! $m->isRoll() && $sheet['cuts']->isNotEmpty())
            <button type="button" @click="show('cutboards', {{ $m->id }})" title="Cut whole boards into ready pieces" class="{{ $btnPrimary }}">
                <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Cut boards
            </button>
        @endif
        <button type="button" @click="show('restock', {{ $m->id }})" title="New {{ $nouns(2) }} arrived: add them" class="{{ $m->isRoll() ? $btnPrimary : $btnOutline }}">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add stock
        </button>
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
    </div>
    @else
    <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-lg bg-slate-50 p-3">
            <p class="text-xs text-slate-500">In stock</p>
            <p class="text-xl font-semibold text-ink">
                {{ $fmt($m->stock_quantity) }} <span class="text-sm font-normal text-slate-500">{{ $m->unit }}</span>
            </p>
            <p class="text-xs text-slate-500">
                @if($store->count() || $warehouse->count())
                    Unopened: {{ $store->count() }} in the store{{ $warehouse->count() ? ', ' . $warehouse->count() . ' in the warehouse' : '' }}
                @else
                    No unopened packs
                @endif
            </p>
        </div>
        <div class="rounded-lg bg-slate-50 p-3 min-w-0">
            <p class="text-xs text-slate-500">Pack in use</p>
            @if($active)
                <p class="text-sm font-medium text-ink">{{ $fmt($active->remaining_quantity) }} of {{ $fmt($active->opening_quantity) }} {{ $m->unit }} left</p>
                <div class="mt-1.5 rounded-full bg-slate-100 overflow-hidden" style="height:6px;">
                    <div style="height:6px; width:{{ $card['pct'] }}%; background:{{ $barColor }};"></div>
                </div>
                <p class="text-[11px] text-slate-400 mt-1 truncate" title="Pack code">{{ $active->batch_number }}</p>
            @elseif($store->count())
                <p class="text-sm text-ink">None open yet</p>
                <p class="text-xs text-slate-500">The next pack opens by itself when it's used.</p>
            @else
                <p class="text-sm text-ink">Nothing in stock</p>
                <p class="text-xs text-slate-500">Press Add stock when new stock arrives.</p>
            @endif
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @if($kind === 'continuous')
            <button type="button" @click="show('pull', {{ $m->id }})" title="Type how much you used for a job. It comes off the pack in use." class="{{ $btnPrimary }}">
                <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Record use
            </button>
            <button type="button" @click="show('restock', {{ $m->id }})" title="New stock arrived: type how much" class="{{ $btnOutline }}">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add stock
            </button>
        @else
            <button type="button" @click="show('restock', {{ $m->id }})" title="New stock arrived: type how many" class="{{ $btnPrimary }}">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add stock
            </button>
        @endif
        @if($active)
            <button type="button" @click="show('loss', {{ $m->id }})" title="Some got damaged or wasted: take it off the stock" class="{{ $btnOutline }}">
                <i data-lucide="triangle-alert" class="w-3.5 h-3.5"></i> Report damage
            </button>
            <button type="button" @click="show('count', {{ $m->id }})" title="The number is wrong: type what's really there" class="{{ $btnOutline }}">
                <i data-lucide="calculator" class="w-3.5 h-3.5"></i> Fix count
            </button>
        @endif
        @if($store->count())
            <button type="button" @click="show('open', {{ $m->id }})" title="Start using the next unopened pack. The one in use now is marked finished." class="{{ $btnOutline }}">
                <i data-lucide="package-open" class="w-3.5 h-3.5"></i> Start a new pack
            </button>
        @endif
        @if($warehouse->count())
            <button type="button" @click="show('transfer', {{ $m->id }})" title="Move the oldest unopened pack from the warehouse to the store" class="{{ $btnOutline }}">
                <i data-lucide="truck" class="w-3.5 h-3.5"></i> Bring a pack from the warehouse
            </button>
        @endif
    </div>
    @endif

    {{-- Footer, kept at the bottom so it lines up across a row of cards: History on the left; on
         the right, the rarely used actions (moving it to the other page, archiving), out of the way --}}
    <div class="mt-auto pt-4">
        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-slate-100">
            @if($card['history']->count())
                <button type="button" @click="history = !history" :aria-expanded="history" aria-controls="history-{{ $m->id }}"
                        class="inline-flex items-center gap-1 text-sm text-brand-600">
                    <i data-lucide="chevron-right" class="w-4 h-4 transition-transform" :class="history ? 'rotate-90' : ''"></i> History
                </button>
            @endif
            <div class="ml-auto relative" @click.outside="more = false" @keydown.escape.window="more = false">
                <button type="button" @click="more = !more" :aria-expanded="more" aria-haspopup="menu"
                        class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-ink rounded-lg px-2 py-1 hover:bg-slate-50">
                    More <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
                </button>
                <div x-show="more" style="display:none;" x-transition.opacity role="menu"
                     class="absolute right-0 bottom-full mb-1 z-20 w-64 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                    {{-- Boards stay Discrete and rolls Continuous: they are counted whole either way --}}
                    @unless($isSheet)
                    <form method="POST" action="{{ route('stock.type', $m) }}">
                        @csrf
                        <input type="hidden" name="inventory_type" value="{{ $otherKind }}">
                        <button type="submit" role="menuitem" class="{{ $menuItem }} text-slate-700 hover:bg-slate-100">
                            <i data-lucide="arrow-left-right" class="w-4 h-4 flex-shrink-0"></i>
                            <span>Move to {{ ucfirst($otherKind) }}<span class="block text-xs text-slate-500">{{ $otherKind === 'discrete' ? "If it's counted one by one, not measured" : "If it's measured, not counted one by one" }}</span></span>
                        </button>
                    </form>
                    @endunless
                    <button type="button" role="menuitem" @click="more = false; show('archive', {{ $m->id }})" class="{{ $menuItem }} text-red-600 hover:bg-red-50">
                        <i data-lucide="archive" class="w-4 h-4 flex-shrink-0"></i>
                        <span>Archive<span class="block text-xs text-slate-500">No longer used: hide it from this page</span></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    @if($card['history']->count())
        <div id="history-{{ $m->id }}" x-show="history" style="display:none;" class="mt-3">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-100">
                            <th class="py-2 pr-3">When</th><th class="pr-3">Pack</th><th class="pr-3">What</th>
                            <th class="pr-3 text-right">Amount</th><th class="pr-3">Details</th><th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($card['history'] as $e)
                        <tr class="border-b border-slate-50">
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
