@extends('layouts.app')
@section('title', 'Raw Materials')

@section('content')
@inject('packs', 'App\Services\PackService')
@php
    $btnOutline = 'inline-flex items-center gap-1.5 text-sm border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-3 py-1.5';
@endphp

@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
        {{ $errors->first() }}
    </div>
@endif

<div x-data="stockPage()">
    @include('stock._tabs')

    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <p class="text-sm text-slate-500 dark:text-slate-400 max-w-2xl">
            Whole units, like a roll of fabric. How much one job uses can't be measured, so a unit is removed by hand
            when it is used up. Sales are tied to the unit in use by its ID.
        </p>
        <button type="button" @click="show('add')"
            class="text-sm px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5 flex-shrink-0">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add raw material
        </button>
    </div>

    @include('stock._setup')

    @if($cards->isEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-10 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400">No raw materials yet. Add one with the button above.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach($cards as $card)
        @php
            $mat = $card['material'];
            $cur = $card['current'];
            $state = $card['state'];
            $cs = $cur ? ($stats[$cur->id] ?? []) : [];
            $sales = $cs['sales'] ?? 0;
            $errs = $cs['error_count'] ?? 0;
            [$pillText, $pillClass] = [
                'ok' => ['In use', 'bg-emerald-100 text-emerald-700'],
                'low' => ['Stock running low', 'bg-amber-100 text-amber-700'],
                'none' => ['None in use', 'bg-red-100 text-red-700'],
                'new' => ['Not started', 'bg-slate-100 text-slate-600'],
            ][$state];
        @endphp
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium text-ink dark:text-white truncate">{{ $mat->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Counted in {{ $mat->unit }}</p>
                </div>
                <span class="px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-lg bg-slate-50 dark:bg-slate-700/40 p-3">
                    <p class="text-xs text-slate-500 dark:text-slate-400">In stock</p>
                    <p class="text-xl font-semibold text-ink dark:text-white">
                        {{ $packs->fmt($mat->stock_quantity) }} <span class="text-sm font-normal text-slate-500 dark:text-slate-400">{{ $mat->unit }}</span>
                    </p>
                </div>
                <div class="rounded-lg bg-slate-50 dark:bg-slate-700/40 p-3 min-w-0">
                    <p class="text-xs text-slate-500 dark:text-slate-400">In use</p>
                    @if($cur)
                        <p class="text-sm font-medium text-ink dark:text-white truncate" title="{{ $cur->code }}">{{ $cur->code }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $sales }} {{ $sales === 1 ? 'sale' : 'sales' }}@if($errs > 0), <span class="text-red-600">{{ $errs }} {{ $errs === 1 ? 'error' : 'errors' }}</span>@endif
                        </p>
                    @else
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $state === 'new' ? 'Not started yet' : 'None' }}</p>
                    @endif
                </div>
            </div>

            @if($state === 'none')
                <p class="mt-3 text-xs text-red-600">Sales that need {{ $mat->name }} are blocked until you start the next one.</p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" @click="show('open', {{ $mat->id }})"
                    class="inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5">
                    <i data-lucide="package-open" class="w-3.5 h-3.5"></i> {{ $state === 'new' ? 'Start using one' : 'Use next one' }}
                </button>
                @if($cur)
                    <button type="button" @click="show('usage', {{ $mat->id }})" class="{{ $btnOutline }}">Log use or error</button>
                    <button type="button" @click="show('close', {{ $mat->id }})"
                        class="inline-flex items-center gap-1.5 text-sm border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg px-3 py-1.5">
                        Mark used up
                    </button>
                @endif
                <button type="button" @click="show('adjust', {{ $mat->id }})" class="{{ $btnOutline }}">Add or remove stock</button>
            </div>

            <datalist id="batches-{{ $mat->id }}">
                @foreach($card['batches'] as $b)
                    <option value="{{ $b->label }}">
                @endforeach
            </datalist>

            @if($card['history']->count())
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm text-brand-600 dark:text-brand-300">History</summary>
                    <div class="overflow-x-auto mt-2">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                                    <th class="py-2 pr-3">Unit</th><th class="pr-3">Started</th><th class="pr-3">Used up</th>
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
                        </table>
                    </div>
                </details>
            @endif
        </div>
    @endforeach
    </div>

    @include('stock._dialogs', ['kind' => 'raw'])
</div>
@endsection
