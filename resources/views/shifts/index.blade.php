@extends('layouts.app')
@section('title', 'Shifts')

@section('content')
@php $applyFilter = "\$el.closest('form').requestSubmit()"; @endphp
<div class="space-y-6">
    <p class="text-sm text-slate-500">
        When each person started and ended a shift, what they sold, the payments they took, and whether the cash they counted
        at the end matched what the drawer should have. Open a shift to see everything done in it.
    </p>

    <div class="bg-white rounded-xl border border-slate-200">
        <form method="GET" class="flex flex-wrap gap-3 p-4 border-b border-slate-100">
            <div class="w-56">
                <x-select-menu name="user"
                    :options="collect(['' => 'Everyone'])->union($people->pluck('name', 'id'))->all()"
                    :selected="request('user')"
                    placeholder="Everyone"
                    :onchange="$applyFilter" />
            </div>
        </form>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="p-4">Person</th><th class="px-2">Started</th><th class="px-2">Ended</th><th class="px-2 text-right">Sales</th>
                    <th class="px-2 text-right">Payments taken</th><th class="px-2 text-right">Cash difference</th><th class="px-4"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($shifts as $shift)
                @php
                    $sold = $sales[$shift->id] ?? null;
                    $paid = $taken[$shift->id] ?? null;
                    $diff = $shift->status === 'closed' ? (float) $shift->variance : null;
                @endphp
                <tr class="border-b border-slate-50">
                    <td class="p-4 font-medium text-ink"><a href="{{ route('shifts.show', $shift) }}" class="hover:text-brand-600 hover:underline">{{ $shift->user->name ?? '-' }}</a></td>
                    <td class="px-2 whitespace-nowrap text-slate-600">{{ $shift->opened_at->format('M j, h:i A') }}</td>
                    <td class="px-2 whitespace-nowrap text-slate-600">
                        @if($shift->closed_at)
                            {{ $shift->closed_at->isSameDay($shift->opened_at) ? $shift->closed_at->format('h:i A') : $shift->closed_at->format('M j, h:i A') }}
                            <span class="block text-xs text-slate-400">{{ $shift->opened_at->diffForHumans($shift->closed_at, true) }}</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">On shift now</span>
                        @endif
                    </td>
                    <td class="px-2 text-right whitespace-nowrap">
                        ₱{{ number_format($sold->total ?? 0, 2) }}
                        <span class="block text-xs text-slate-400">{{ $sold->count ?? 0 }} {{ ($sold->count ?? 0) == 1 ? 'sale' : 'sales' }}</span>
                    </td>
                    <td class="px-2 text-right whitespace-nowrap">
                        ₱{{ number_format($paid->total ?? 0, 2) }}
                        <span class="block text-xs text-slate-400">₱{{ number_format($paid->cash ?? 0, 2) }} cash</span>
                    </td>
                    <td class="px-2 text-right whitespace-nowrap font-medium {{ $diff === null ? 'text-slate-400' : (abs($diff) >= 0.01 ? 'text-red-600' : 'text-emerald-600') }}">
                        {{ $diff === null ? 'Not counted yet' : (abs($diff) < 0.01 ? 'None' : ($diff < 0 ? '−' : '+') . '₱' . number_format(abs($diff), 2)) }}
                    </td>
                    <td class="px-4 text-right">
                        <a href="{{ route('shifts.show', $shift) }}" class="text-xs font-medium text-brand-600 hover:underline whitespace-nowrap">Open</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="p-8 text-center text-sm text-slate-400">No shifts yet. Staff start one on Billing/POS.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $shifts->links() }}</div>
    </div>
</div>
@endsection
