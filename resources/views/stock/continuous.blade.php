@extends('layouts.app')
@section('title', 'Continuous Raw Materials')
@section('shows-errors', '1')

@section('content')
@if($errors->any())
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
        {{ $errors->first() }}
    </div>
@endif

<div x-data="stockPage()">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <p class="text-sm text-slate-500 max-w-2xl">
            Bulk stock measured by length or volume, like fabric rolls, thread and ink. It can't be deducted per item, so log each pull for use: it comes out of the active batch.
            Rolls of a set size, like tarpaulin, are measured into one-foot blocks at full width: a sale takes whole feet from the narrowest roll its size fits.
        </p>
        <div class="flex gap-2 flex-shrink-0">
            <a href="{{ route('materials.archive', ['from' => 'continuous']) }}" class="text-sm px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5">
                <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
            </a>
            <button type="button" @click="show('add')"
                class="text-sm px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add continuous material
            </button>
        </div>
    </div>

    @include('stock._setup')

    @if($cards->isEmpty())
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
            <p class="text-sm text-slate-500">No continuous raw materials yet. Add one, or pick Continuous for a material in the list above.</p>
        </div>
    @endif

    @include('stock._search')

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($cards as $card)
            @include('stock._card', ['card' => $card, 'kind' => 'continuous'])
        @endforeach
    </div>

    @include('stock._dialogs', ['kind' => 'continuous'])
</div>
@endsection
