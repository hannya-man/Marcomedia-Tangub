@extends('layouts.app')
@section('title', 'Materials')

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
            Pieces you can count, like a pack of 1,000. Sales and jobs take pieces out of the open pack.
            When it runs out, open the next pack and the old one closes.
        </p>
        <button type="button" @click="show('add')"
            class="text-sm px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5 flex-shrink-0">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add material
        </button>
    </div>

    @include('stock._setup')

    @if($cards->isEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-10 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400">No materials yet. Add one with the button above.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach($cards as $card)
        @php
            $mat = $card['material'];
            $cur = $card['current'];
            $state = $card['state'];
            $cs = $cur ? ($stats[$cur->id] ?? []) : [];
            [$pillText, $pillClass] = [
                'ok' => ['Open', 'bg-emerald-100 text-emerald-700'],
                'low' => ['Running low', 'bg-amber-100 text-amber-700'],
                'empty' => ['Empty, open a new pack', 'bg-red-100 text-red-700'],
                'none' => ['No pack open', 'bg-red-100 text-red-700'],
                'new' => ['Not started', 'bg-slate-100 text-slate-600'],
            ][$state];
            $barColor = ['ok' => '#146c84', 'low' => '#d97706', 'empty' => '#ef4444', 'none' => '#ef4444', 'new' => '#94a3b8'][$state];
        @endphp
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-medium text-ink dark:text-white truncate">{{ $mat->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Counted in {{ $mat->unit }}</p>
                </div>
                <span class="px-2 py-1 rounded-full text-xs font-medium flex-shrink-0 {{ $pillClass }}">{{ $pillText }}</span>
            </div>

            @if($cur)
                <div class="mt-4">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <p class="font-medium text-ink dark:text-white truncate" title="{{ $cur->code }}">{{ $cur->code }}</p>
                        <p class="text-slate-500 dark:text-slate-400 flex-shrink-0">{{ $packs->fmt($cur->remaining_qty) }} of {{ $packs->fmt($cur->initial_qty) }} {{ $mat->unit }} left</p>
                    </div>
                    <div class="mt-2 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden" style="height:8px;">
                        <div style="height:8px; width:{{ $card['pct'] }}%; background:{{ $barColor }};"></div>
                    </div>
                    <p class="text-xs text-slate-400 mt-1.5">
                        Opened {{ $cur->opened_at?->format('M j, Y') }}@if(($cs['error_count'] ?? 0) > 0)<span class="text-red-600">, {{ $packs->fmt($cs['errors']) }} {{ $mat->unit }} lost to errors</span>@endif
                    </p>
                </div>
            @elseif($state === 'new')
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                    On hand: <span class="font-medium text-ink dark:text-white">{{ $packs->fmt($mat->stock_quantity) }} {{ $mat->unit }}</span>.
                    Open the first pack to give it an ID and tie sales to it.
                </p>
            @else
                <p class="mt-4 text-sm text-red-600">
                    No pack is open, so sales and uses that need {{ $mat->name }} are blocked until you open one.
                </p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" @click="show('open', {{ $mat->id }})"
                    class="inline-flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5">
                    <i data-lucide="package-open" class="w-3.5 h-3.5"></i> {{ $state === 'new' ? 'Open first pack' : 'Open new pack' }}
                </button>
                @if($cur)
                    <button type="button" @click="show('usage', {{ $mat->id }})" class="{{ $btnOutline }}">Log use or error</button>
                    <button type="button" @click="show('adjust', {{ $mat->id }})" class="{{ $btnOutline }}">Adjust</button>
                    <button type="button" @click="show('close', {{ $mat->id }})"
                        class="inline-flex items-center gap-1.5 text-sm border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg px-3 py-1.5">
                        Close pack
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
                    <summary class="cursor-pointer text-sm text-brand-600 dark:text-brand-300">History</summary>
                    <div class="overflow-x-auto mt-2">
                        <table class="w-full text-xs">
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
                        </table>
                    </div>
                </details>
            @endif
        </div>
    @endforeach
    </div>

    @include('stock._dialogs', ['kind' => 'material'])
</div>
@endsection
