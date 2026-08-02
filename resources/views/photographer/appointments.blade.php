@extends('layouts.photographer')
@section('title', 'My Appointments')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div class="flex gap-1 bg-slate-100 dark:bg-slate-700 rounded-lg p-1">
        <button class="text-xs px-3 py-1.5 rounded-md bg-white dark:bg-slate-600 shadow-sm font-medium dark:text-white">Calendar</button>
        <button class="text-xs px-3 py-1.5 rounded-md text-slate-500 dark:text-slate-300">List</button>
    </div>
    <div class="text-xs px-3 py-1.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 flex items-center gap-1">
        <i data-lucide="lock" class="w-3.5 h-3.5"></i> View-only access
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Client</th><th>Service</th><th>Date / Time</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
                <tr class="border-b border-slate-50 dark:border-slate-700 cursor-pointer" onclick="window.location='{{ route('photographer.appointments.show', 1) }}'">
                    <td class="p-4 font-medium text-ink dark:text-white">Reyes Family</td><td>Prenup Shoot</td><td>Jul 10, 2:00 PM</td>
                    <td><span class="px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Confirmed</span></td>
                    <td class="text-brand-600 text-xs">View</td>
                </tr>
                <tr class="cursor-pointer" onclick="window.location='{{ route('photographer.appointments.show', 2) }}'">
                    <td class="p-4 font-medium text-ink dark:text-white">B. Santos</td><td>Graduation Package</td><td>Jul 11, 9:00 AM</td>
                    <td><span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Pending</span></td>
                    <td class="text-brand-600 text-xs">View</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="bg-ink rounded-xl p-5 text-white">
        <p class="text-xs uppercase tracking-wide text-slate-400 mb-3">Today's Schedule</p>
        <div class="border-l-2 border-brand-500 pl-3 text-sm">
            <p class="font-medium">Prenup Shoot — Reyes Family</p>
            <p class="text-xs text-slate-400">2:00 PM · Studio A</p>
        </div>
    </div>
</div>
@endsection
