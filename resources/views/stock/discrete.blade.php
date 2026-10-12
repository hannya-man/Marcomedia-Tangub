@extends('layouts.app')
@section('title', 'Discrete Materials')
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
            Single pieces counted one by one, like blank PVC cards, RFID chips and fasteners. Sales take them out of the active batch automatically, one for one.
            Boards of a set size, like sintra board, are cut whole into standard sizes with a fixed yield: a sale takes the pieces.
        </p>
        <div class="flex gap-2 flex-shrink-0">
            <a href="{{ route('materials.archive', ['from' => 'discrete']) }}" class="text-sm px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5">
                <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
            </a>
            <button type="button" @click="show('add')"
                class="text-sm px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add discrete material
            </button>
        </div>
    </div>

    @include('stock._setup')

    @if($cards->isEmpty())
        <div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
            <p class="text-sm text-slate-500">No discrete materials yet. Add one, or pick Discrete for a material in the list above.</p>
        </div>
    @endif

    @include('stock._search')

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($cards as $card)
            @include('stock._card', ['card' => $card, 'kind' => 'discrete'])
        @endforeach
    </div>

    @include('stock._dialogs', ['kind' => 'discrete'])
</div>
@endsection
