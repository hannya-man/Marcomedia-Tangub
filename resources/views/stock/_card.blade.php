{{-- One material on the Continuous or Discrete page. Needs $card and $kind. --}}
@php
    $fmt = fn ($n) => \App\Services\Inventory\StockAlertService::formatQty($n);
    $m = $card['m'];
    $active = $card['active'];
    $store = $card['sealedStore'];
    $warehouse = $card['sealedWarehouse'];
    [$pillText, $pillClass] = [
        'ok' => ['In stock', 'bg-emerald-100 text-emerald-700'],
        'low' => ['Low stock', 'bg-amber-100 text-amber-700'],
        'out' => ['Out of stock', 'bg-red-100 text-red-700'],
    ][$card['state']];
    $barColor = ['ok' => '#146c84', 'low' => '#d97706', 'out' => '#ef4444'][$card['state']];
    $btnOutline = 'inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-600 hover:bg-slate-50 rounded-lg px-3 py-1.5';
    $btnPrimary = 'inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5';
    $btnDanger = 'inline-flex items-center gap-1.5 text-sm border border-red-200 text-red-600 hover:bg-red-50 rounded-lg px-3 py-1.5';
    $otherKind = $kind === 'continuous' ? 'discrete' : 'continuous';
    // Boards and rolls (sintra board, tarpaulin): counted whole, and cut whole into standard pieces.
    $sheet = $card['sheet'];
    $isSheet = $sheet !== null;
    $noun = $isSheet ? $m->sheetNoun() : 'pack';
    $nouns = fn ($n) => $noun . ($n == 1 ? '' : 's');
@endphp
<div x-data="{ history: false }" x-show="shown({{ $m->id }})" class="bg-white rounded-xl border border-slate-200 p-5 flex flex-col">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-medium text-ink truncate">{{ $m->name }}</p>
            <p class="text-xs text-slate-500">
                @if($isSheet)
                    {{ $m->sheetLabel() }} {{ $nouns(2) }} · Code {{ $m->code ?? '-' }}
                @else
                    Counted in {{ $m->unit }} · Code {{ $m->code ?? '-' }}
                @endif
            </p>
        </div>
        <span class="px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
    </div>

    @if($isSheet)
    <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-lg bg-slate-50 p-3">
            <p class="text-xs text-slate-500">In stock</p>
            <p class="text-xl font-semibold text-ink">
                {{ $sheet['whole'] }} <span class="text-sm font-normal text-slate-500">{{ $nouns($sheet['whole']) }}</span>
            </p>
            <p class="text-xs text-slate-500">
                Whole, in the store{{ $sheet['warehouse'] ? ', and ' . $sheet['warehouse'] . ' in the warehouse' : '' }}
            </p>
            @if($sheet['part_used'])
                <p class="text-xs text-amber-600 mt-0.5" title="{{ $sheet['part_used']->batch_number }}">
                    1 part-used from before fixed yields: {{ $fmt($sheet['part_used']->remaining_quantity) }} sq ft. Write it off as damaged.
                </p>
            @endif
        </div>
        <div class="rounded-lg bg-slate-50 p-3 min-w-0">
            @if($m->isRoll())
                <p class="text-xs text-slate-500">Measured off</p>
                <p class="text-xl font-semibold text-ink">{{ $fmt($sheet['ready']) }} <span class="text-sm font-normal text-slate-500">ft</span></p>
                <p class="text-xs text-slate-500">One roll is {{ $sheet['cuts']->first()['piece']->cut_yield ?? 0 }} ft, {{ $fmt($m->sheet_width) }} ft wide.</p>
            @else
                <p class="text-xs text-slate-500">Cut and ready</p>
                @php $ready = $sheet['cuts']->filter(fn ($c) => $c['ready'] > 0); @endphp
                @if($ready->isNotEmpty())
                    <p class="text-sm font-medium text-ink">
                        {{ $ready->map(fn ($c) => $fmt($c['ready']) . ' ' . preg_replace('/\s*\(.*\)$/', '', $c['piece']->cutLabel()))->implode(' · ') }}
                    </p>
                @else
                    <p class="text-sm text-slate-500">None yet</p>
                @endif
                <p class="text-xs text-slate-500">A sale cuts the next board when its size runs out.</p>
            @endif
        </div>
    </div>

    @if(! $m->isRoll())
    {{-- The board's standard sizes: how many one board gives (the fixed yield) --}}
    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                    <th class="py-1.5 pr-2 font-medium">Size</th>
                    <th class="py-1.5 px-2 font-medium text-right">From 1 board</th>
                    <th class="py-1.5 px-2 font-medium text-right">Ready</th>
                    <th class="py-1.5 pl-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($sheet['cuts'] as $cut)
                <tr class="border-b border-slate-50">
                    <td class="py-1.5 pr-2 text-ink">{{ $cut['piece']->cutLabel() }}</td>
                    <td class="py-1.5 px-2 text-right whitespace-nowrap">
                        {{ $cut['piece']->cut_yield }}
                        <span class="text-xs text-slate-400" title="Left over as trim, paid for in the pieces' cost">· {{ $cut['trim'] }}% trim</span>
                    </td>
                    <td class="py-1.5 px-2 text-right {{ $cut['ready'] > 0 ? 'text-ink font-medium' : 'text-slate-400' }}">{{ $fmt($cut['ready']) }}</td>
                    <td class="py-1.5 pl-2 whitespace-nowrap text-right">
                        <button type="button" @click="show('cutboards', {{ $m->id }}, {{ $cut['piece']->id }})" class="text-xs text-brand-600 hover:underline">Cut</button>
                        {{-- A misprint: record it on the Rejected page, with this size filled in --}}
                        <a href="{{ route('stock.rejected', ['record' => $cut['piece']->id]) }}" class="text-xs text-slate-500 hover:underline ml-2">Mistake</a>
                        <button type="button" @click="show('removesize', {{ $m->id }}, {{ $cut['piece']->id }})" title="Stop cutting this size" aria-label="Remove {{ $cut['piece']->cutLabel() }}"
                            class="ml-1.5 text-slate-400 hover:text-red-600 align-middle"><i data-lucide="x" class="w-3.5 h-3.5 inline"></i></button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-2 text-xs text-slate-500">No sizes yet. Add the sizes this board is cut into.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        @if(! $m->isRoll() && $sheet['cuts']->isNotEmpty())
            <button type="button" @click="show('cutboards', {{ $m->id }})" class="{{ $btnPrimary }}">
                <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Cut boards
            </button>
        @endif
        <button type="button" @click="show('restock', {{ $m->id }})" class="{{ $m->isRoll() ? $btnPrimary : $btnOutline }}">
            @if($m->isRoll())<i data-lucide="plus" class="w-3.5 h-3.5"></i>@endif Restock
        </button>
        @if($sheet['warehouse'])
            <button type="button" @click="show('transfer', {{ $m->id }})" class="{{ $btnOutline }}">Move {{ $noun }} to store</button>
        @endif
        @if($sheet['whole'] || $sheet['part_used'])
            <button type="button" @click="show('writeoff', {{ $m->id }})" class="{{ $btnOutline }}">Damaged {{ $noun }}</button>
        @endif
        @if(! $m->isRoll())
            <button type="button" @click="show('addsize', {{ $m->id }})" class="{{ $btnOutline }}">
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
                {{ $store->count() }} sealed in store{{ $warehouse->count() ? ', ' . $warehouse->count() . ' in warehouse' : '' }}
            </p>
        </div>
        <div class="rounded-lg bg-slate-50 p-3 min-w-0">
            <p class="text-xs text-slate-500">Active batch</p>
            @if($active)
                <p class="text-sm font-medium text-ink truncate" title="{{ $active->batch_number }}">{{ $active->batch_number }}</p>
                <p class="text-xs text-slate-500">{{ $fmt($active->remaining_quantity) }} of {{ $fmt($active->opening_quantity) }} {{ $m->unit }} left</p>
                <div class="mt-1.5 rounded-full bg-slate-100 overflow-hidden" style="height:6px;">
                    <div style="height:6px; width:{{ $card['pct'] }}%; background:{{ $barColor }};"></div>
                </div>
            @elseif($store->count())
                <p class="text-sm text-slate-500">None open</p>
                <p class="text-xs text-slate-500">{{ $store->first()->batch_number }} opens on the next use.</p>
            @else
                <p class="text-sm text-slate-500">None</p>
                <p class="text-xs text-slate-500">Restock to add a batch.</p>
            @endif
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @if($kind === 'continuous')
            <button type="button" @click="show('pull', {{ $m->id }})" class="{{ $btnPrimary }}">
                <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Pull for use
            </button>
            <button type="button" @click="show('restock', {{ $m->id }})" class="{{ $btnOutline }}">Restock</button>
        @else
            <button type="button" @click="show('restock', {{ $m->id }})" class="{{ $btnPrimary }}">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Restock
            </button>
        @endif
        @if($store->count())
            <button type="button" @click="show('open', {{ $m->id }})" class="{{ $btnOutline }}">Open next pack</button>
        @endif
        @if($warehouse->count())
            <button type="button" @click="show('transfer', {{ $m->id }})" class="{{ $btnOutline }}">Move pack to store</button>
        @endif
        @if($active)
            <button type="button" @click="show('loss', {{ $m->id }})" class="{{ $btnOutline }}">{{ $kind === 'continuous' ? 'Damaged' : 'Damaged pieces' }}</button>
            <button type="button" @click="show('count', {{ $m->id }})" class="{{ $btnOutline }}">Count</button>
        @endif
    </div>
    @endif

    {{-- Footer, kept at the bottom so it lines up across a row of cards: History on the left,
         and on the right, managing the material itself, apart from the everyday stock actions --}}
    <div class="mt-auto pt-4">
        <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-slate-100">
            @if($card['history']->count())
                <button type="button" @click="history = !history" :aria-expanded="history" aria-controls="history-{{ $m->id }}"
                        class="inline-flex items-center gap-1 text-sm text-brand-600">
                    <i data-lucide="chevron-right" class="w-4 h-4 transition-transform" :class="history ? 'rotate-90' : ''"></i> History
                </button>
            @endif
            <div class="ml-auto flex flex-wrap gap-2">
                {{-- Boards stay Discrete and rolls Continuous: they are counted whole either way --}}
                @unless($isSheet)
                <form method="POST" action="{{ route('stock.type', $m) }}">
                    @csrf
                    <input type="hidden" name="inventory_type" value="{{ $otherKind }}">
                    <button type="submit" class="{{ $btnOutline }}">
                        <i data-lucide="arrow-left-right" class="w-3.5 h-3.5"></i> Move to {{ ucfirst($otherKind) }}
                    </button>
                </form>
                @endunless
                <button type="button" @click="show('archive', {{ $m->id }})" class="{{ $btnDanger }}">
                    <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive
                </button>
            </div>
        </div>
    </div>

    @if($card['history']->count())
        <div id="history-{{ $m->id }}" x-show="history" style="display:none;" class="mt-3">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-100">
                            <th class="py-2 pr-3">When</th><th class="pr-3">Batch</th><th class="pr-3">What</th>
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
