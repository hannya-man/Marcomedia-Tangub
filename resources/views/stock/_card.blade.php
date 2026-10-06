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
    $btnOutline = 'inline-flex items-center gap-1.5 text-sm border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-3 py-1.5';
    $btnPrimary = 'inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5';
    $btnDanger = 'inline-flex items-center gap-1.5 text-sm border border-red-200 dark:border-red-900 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg px-3 py-1.5';
    $otherKind = $kind === 'continuous' ? 'discrete' : 'continuous';
    // Sheet materials (sintra board): stock in sq ft, one batch per sheet, used per job.
    $isSheet = $kind === 'continuous' && $m->isSheet();
    $pack = $isSheet ? 'sheet' : 'pack';
@endphp
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-medium text-ink dark:text-white truncate">{{ $m->name }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                @if($isSheet)
                    {{ $m->sheetLabel() }} sheets, {{ $fmt($m->sheetArea()) }} sq ft each · Code {{ $m->code ?? '-' }}
                @else
                    Counted in {{ $m->unit }} · Code {{ $m->code ?? '-' }}
                @endif
            </p>
        </div>
        <span class="px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3">
        <div class="rounded-lg bg-slate-50 dark:bg-slate-700/40 p-3">
            <p class="text-xs text-slate-500 dark:text-slate-400">In stock</p>
            <p class="text-xl font-semibold text-ink dark:text-white">
                {{ $fmt($m->stock_quantity) }} <span class="text-sm font-normal text-slate-500 dark:text-slate-400">{{ $m->unit }}</span>
            </p>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ $store->count() }} sealed {{ $isSheet ? ($store->count() === 1 ? 'sheet ' : 'sheets ') : '' }}in store{{ $warehouse->count() ? ', ' . $warehouse->count() . ' in warehouse' : '' }}
            </p>
        </div>
        <div class="rounded-lg bg-slate-50 dark:bg-slate-700/40 p-3 min-w-0">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $isSheet ? 'Open sheet' : 'Active batch' }}</p>
            @if($active)
                <p class="text-sm font-medium text-ink dark:text-white truncate" title="{{ $active->batch_number }}">{{ $active->batch_number }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $fmt($active->remaining_quantity) }} of {{ $fmt($active->opening_quantity) }} {{ $m->unit }} left</p>
                <div class="mt-1.5 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden" style="height:6px;">
                    <div style="height:6px; width:{{ $card['pct'] }}%; background:{{ $barColor }};"></div>
                </div>
            @elseif($store->count())
                <p class="text-sm text-slate-500 dark:text-slate-400">None open</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $store->first()->batch_number }} opens on the next use.</p>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">None</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Restock to add a batch.</p>
            @endif
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @if($isSheet)
            <button type="button" @click="show('cut', {{ $m->id }})" class="{{ $btnPrimary }}">
                <i data-lucide="scissors" class="w-3.5 h-3.5"></i> Cut for a job
            </button>
            <button type="button" @click="show('restock', {{ $m->id }})" class="{{ $btnOutline }}">Restock</button>
        @elseif($kind === 'continuous')
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
            <button type="button" @click="show('open', {{ $m->id }})" class="{{ $btnOutline }}">Open next {{ $pack }}</button>
        @endif
        @if($warehouse->count())
            <button type="button" @click="show('transfer', {{ $m->id }})" class="{{ $btnOutline }}">Move {{ $pack }} to store</button>
        @endif
        @if($active)
            <button type="button" @click="show('loss', {{ $m->id }})" class="{{ $btnOutline }}">{{ $kind === 'continuous' ? 'Damaged' : 'Damaged pieces' }}</button>
            <button type="button" @click="show('count', {{ $m->id }})" class="{{ $btnOutline }}">Count</button>
        @endif
        @if($isSheet)
            {{-- A misprint: record it on the Rejected page, with this material filled in --}}
            <a href="{{ route('stock.rejected', ['record' => $m->id]) }}" class="{{ $btnOutline }}">
                <i data-lucide="x-circle" class="w-3.5 h-3.5"></i> Mistake
            </a>
        @endif
        {{-- Managing the material itself, kept apart on the right from the everyday stock actions --}}
        <div class="flex flex-wrap gap-2" style="margin-left:auto;">
            {{-- A sheet material only makes sense as continuous: it is cut by size --}}
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

    @if($card['history']->count())
        <details class="mt-4">
            <summary class="cursor-pointer text-sm text-brand-600 dark:text-brand-300">History</summary>
            <div class="overflow-x-auto mt-2">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                            <th class="py-2 pr-3">When</th><th class="pr-3">Batch</th><th class="pr-3">What</th>
                            <th class="pr-3 text-right">Amount</th><th class="pr-3">Details</th><th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($card['history'] as $e)
                        <tr class="border-b border-slate-50 dark:border-slate-700">
                            <td class="py-2 pr-3 whitespace-nowrap text-slate-500 dark:text-slate-400">{{ optional($e['at'])->format('M j, h:i A') }}</td>
                            <td class="pr-3 whitespace-nowrap font-medium text-ink dark:text-white">{{ $e['batch'] }}</td>
                            <td class="pr-3 whitespace-nowrap">{{ $e['what'] }}</td>
                            <td class="pr-3 text-right whitespace-nowrap {{ $e['qty'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ $e['qty'] >= 0 ? '+' : '' }}{{ $fmt($e['qty']) }}</td>
                            <td class="pr-3 text-slate-500 dark:text-slate-400">{{ $e['detail'] ?? '-' }}</td>
                            <td class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $e['by'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif
</div>
