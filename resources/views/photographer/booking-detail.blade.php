@extends('layouts.photographer')
@section('title', 'Booking Detail')

@section('content')
<a href="{{ route('photographer.appointments.index') }}" class="text-xs text-brand-600 flex items-center gap-1 mb-4">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to My Appointments
</a>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <p class="text-lg font-semibold text-ink dark:text-white">Reyes Family</p>
                <p class="text-xs text-slate-500">Prenup Shoot Package</p>
            </div>
            <span class="px-3 py-1 rounded-full text-xs bg-emerald-100 text-emerald-700">Confirmed</span>
        </div>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><p class="text-xs text-slate-500">Date</p><p class="text-ink dark:text-white">July 10, 2026</p></div>
            <div><p class="text-xs text-slate-500">Time</p><p class="text-ink dark:text-white">2:00 PM</p></div>
            <div><p class="text-xs text-slate-500">Location</p><p class="text-ink dark:text-white">Studio A, Marcomedia</p></div>
            <div><p class="text-xs text-slate-500">Contact</p><p class="text-ink dark:text-white">0917-xxx-xxxx</p></div>
        </div>
        <div class="border-t border-slate-100 dark:border-slate-700 mt-6 pt-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Notes</p>
            <p class="text-sm text-slate-600 dark:text-slate-300">Outdoor + studio combo package, 2 outfit changes, client requested golden hour timing.</p>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
        <p class="font-medium text-ink dark:text-white mb-4">Actions</p>
        <div class="space-y-2">
            <form method="POST" action="{{ route('photographer.appointments.markDone', 1) }}">
                @csrf
                <button type="submit" class="w-full bg-brand-600 text-white text-sm rounded-lg py-2.5 flex items-center justify-center gap-2">
                    <i data-lucide="check" class="w-4 h-4"></i> Mark as Done
                </button>
            </form>
            <form method="POST" action="{{ route('photographer.appointments.reschedule', 1) }}">
                @csrf
                <button type="submit" class="w-full border border-slate-300 dark:border-slate-600 text-sm rounded-lg py-2.5 text-slate-600 dark:text-slate-300 flex items-center justify-center gap-2">
                    <i data-lucide="calendar-clock" class="w-4 h-4"></i> Request Reschedule
                </button>
            </form>
        </div>
        <p class="text-xs text-slate-400 mt-4">No editing or deletion permitted for this role.</p>
    </div>
</div>
@endsection
