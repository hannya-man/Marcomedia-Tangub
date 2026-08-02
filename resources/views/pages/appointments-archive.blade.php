@extends('layouts.app')
@section('title', 'Appointments Archive')

@section('content')
<a href="{{ route('appointments.index') }}"
   class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-4 py-2 mb-4 shadow-sm">
    <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Appointments
</a>

<div x-data="{ viewing: null }">
    @php
        $archivedJson = $archived->map(fn($a) => [
            'id' => $a->id,
            'client_name' => $a->client_name,
            'contact_number' => $a->contact_number,
            'email' => $a->email,
            'service' => $a->service,
            'appointment_date' => $a->appointment_date->format('M j, Y'),
            'appointment_time' => \Carbon\Carbon::parse($a->appointment_time)->format('g:i A'),
            'location' => $a->location,
            'notes' => $a->notes,
            'archived_at' => $a->archived_at?->format('M j, Y g:i A'),
            'archived_reason' => $a->archived_reason === 'expired' ? 'Expired (date passed)' : 'Manually deleted',
        ]);
    @endphp
    <input type="hidden" id="archived-appointments-data" value='@json($archivedJson)'>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-2">
            <i data-lucide="archive" class="w-4 h-4 text-slate-500"></i>
            <p class="font-medium text-ink dark:text-white">Archived Appointments</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Client</th><th>Service</th><th>Original Date</th><th>Archived</th><th>Reason</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($archived as $appt)
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-4 flex items-center gap-2"><i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>{{ $appt->client_name }}</td>
                    <td>{{ $appt->service }}</td>
                    <td class="text-slate-500 dark:text-slate-400">{{ $appt->appointment_date->format('M j, Y') }}</td>
                    <td class="text-slate-500 dark:text-slate-400">{{ $appt->archived_at?->format('M j, Y g:i A') }}</td>
                    <td>
                        <span class="px-2 py-0.5 rounded-full text-xs {{ $appt->archived_reason === 'expired' ? 'bg-slate-100 text-slate-600' : 'bg-red-100 text-red-700' }}">
                            {{ $appt->archived_reason === 'expired' ? 'Expired' : 'Deleted' }}
                        </span>
                    </td>
                    <td class="whitespace-nowrap">
                        <button type="button" onclick='viewArchivedDetails({{ $appt->id }})'
                            class="inline-flex items-center gap-1 text-xs bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-lg px-2.5 py-1.5 mr-1.5">
                            <i data-lucide="eye" class="w-3 h-3"></i> View Details
                        </button>
                        <form method="POST" action="{{ route('appointments.restore', $appt) }}" class="inline">
                            @csrf
                            <button class="inline-flex items-center gap-1 text-xs bg-brand-50 dark:bg-brand-600/20 text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-600/30 rounded-lg px-2.5 py-1.5">
                                <i data-lucide="rotate-ccw" class="w-3 h-3"></i> Restore
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-sm text-slate-400">Nothing archived yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- VIEW DETAILS MODAL --}}
    <div x-show="viewing !== null" style="display:none;" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="viewing = null">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6" x-show="viewing !== null" x-transition.scale>
            <div class="flex items-center justify-between mb-4">
                <p class="font-semibold text-ink dark:text-white flex items-center gap-2"><i data-lucide="archive" class="w-4 h-4"></i> Archived Appointment Details</p>
                <button @click="viewing = null" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <div class="space-y-2 text-sm" x-show="viewing">
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Client</span><span class="font-medium text-ink dark:text-white" x-text="viewing?.client_name"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Contact Number</span><span class="text-ink dark:text-white" x-text="viewing?.contact_number"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Email</span><span class="text-ink dark:text-white" x-text="viewing?.email || '—'"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Service</span><span class="text-ink dark:text-white" x-text="viewing?.service"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Original Date &amp; Time</span><span class="text-ink dark:text-white" x-text="viewing?.appointment_date + ' · ' + viewing?.appointment_time"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Location</span><span class="text-ink dark:text-white" x-text="viewing?.location || '—'"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Archived On</span><span class="text-ink dark:text-white" x-text="viewing?.archived_at"></span></div>
                <div class="flex justify-between py-1.5 border-b border-slate-100 dark:border-slate-700"><span class="text-slate-500">Reason</span><span class="text-ink dark:text-white" x-text="viewing?.archived_reason"></span></div>
                <div class="py-1.5">
                    <span class="text-slate-500 block mb-1">Notes</span>
                    <p class="text-ink dark:text-white text-sm" x-text="viewing?.notes || 'No notes recorded.'"></p>
                </div>
            </div>
            <div class="flex justify-end pt-4">
                <button @click="viewing = null" class="text-sm border border-slate-300 dark:border-slate-600 rounded-lg px-4 py-2 text-slate-600 dark:text-slate-300">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const archivedAppointmentsData = JSON.parse(document.getElementById('archived-appointments-data').value);
function viewArchivedDetails(id) {
    const root = document.querySelector('[x-data*="viewing"]');
    const data = archivedAppointmentsData.find(a => a.id === id);
    if (root && data && window.Alpine) {
        window.Alpine.$data(root).viewing = data;
    }
}
</script>
@endsection
