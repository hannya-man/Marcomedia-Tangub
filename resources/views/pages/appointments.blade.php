@extends('layouts.app')
@section('title', 'Appointments')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div class="flex gap-1 bg-slate-100 dark:bg-slate-700 rounded-lg p-1">
        <button id="btn-view-calendar" onclick="setAppointmentsView('calendar')"
                class="text-xs px-3 py-1.5 rounded-md flex items-center gap-1.5 bg-white dark:bg-slate-600 shadow-sm font-medium dark:text-white">
            <i data-lucide="calendar" class="w-3.5 h-3.5"></i> Calendar
        </button>
        <button id="btn-view-list" onclick="setAppointmentsView('list')"
                class="text-xs px-3 py-1.5 rounded-md flex items-center gap-1.5 text-slate-500 dark:text-slate-300">
            <i data-lucide="list" class="w-3.5 h-3.5"></i> List
        </button>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('appointments.archive') }}" class="text-xs px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5">
            <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
        </a>
        <button type="button" onclick="startCreate('')" class="text-xs px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> New Appointment
        </button>
    </div>
</div>

<div x-data="{ tooltip: null, editing: null, creating: null, confirmingDelete: null }">
    @php
        $appointmentsJson = $appointments->map(fn($a) => [
            'id' => $a->id,
            'client_name' => $a->client_name,
            'contact_number' => $a->contact_number,
            'email' => $a->email,
            'service' => $a->service,
            'appointment_date' => $a->appointment_date->format('Y-m-d'),
            'appointment_time' => substr($a->appointment_time, 0, 5),
            'location' => $a->location,
            'notes' => $a->notes,
            'status' => $a->status,
        ]);
    @endphp
    <input type="hidden" id="appointments-data" value='@json($appointmentsJson)'>

    {{-- CALENDAR VIEW --}}
    <div id="calendar-view" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 relative">
            <div class="flex items-center justify-between mb-4">
                <p class="font-medium text-ink dark:text-white flex items-center gap-2"><i data-lucide="calendar-days" class="w-4 h-4 text-brand-600"></i>{{ $calendarMonth->format('F Y') }}</p>
                <div class="flex gap-1">
                    <a href="{{ route('appointments.index', ['month' => $calendarMonth->copy()->subMonth()->format('Y-m')]) }}" class="w-7 h-7 rounded-lg border border-slate-200 dark:border-slate-600 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
                    <a href="{{ route('appointments.index', ['month' => now()->format('Y-m')]) }}" class="text-[10px] px-2 rounded-lg border border-slate-200 dark:border-slate-600 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700">Today</a>
                    <a href="{{ route('appointments.index', ['month' => $calendarMonth->copy()->addMonth()->format('Y-m')]) }}" class="w-7 h-7 rounded-lg border border-slate-200 dark:border-slate-600 flex items-center justify-center text-slate-500 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
                </div>
            </div>
            <div class="grid grid-cols-7 gap-1 text-center text-xs text-slate-400 mb-2">
                <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>

            @php
                $daysInMonth = $calendarMonth->daysInMonth;
                $startOffset = $calendarMonth->copy()->startOfMonth()->dayOfWeek;
                $byDay = $appointments->groupBy(fn($a) => $a->appointment_date->format('Y-m') === $calendarMonth->format('Y-m') ? $a->appointment_date->day : 'other');
            @endphp
            <div class="grid grid-cols-7 gap-1">
                @for ($i = 0; $i < $startOffset; $i++)<div></div>@endfor
                @for ($d = 1; $d <= $daysInMonth; $d++)
                    @php
                        $dayAppointments = $byDay->get($d, collect());
                        $cellDate = $calendarMonth->copy()->startOfMonth()->addDays($d - 1)->format('Y-m-d');
                        $isToday = $cellDate === now()->format('Y-m-d');
                        $isPast = $cellDate < now()->format('Y-m-d');
                        $tag = $isPast ? 'div' : 'button';
                    @endphp
                    <{{ $tag }} @if(!$isPast) type="button" onclick="startCreate('{{ $cellDate }}')" @endif
                        class="aspect-square rounded-lg p-1 text-xs relative block w-full text-left transition-shadow
                        {{ $isPast ? 'border border-slate-100 dark:border-slate-800 text-slate-300 dark:text-slate-600 opacity-60 cursor-default' : 'hover:ring-2 hover:ring-brand-400' }}
                        {{ $isToday ? 'border-2 border-brand-500 bg-brand-50 dark:bg-brand-600/20' : (!$isPast ? 'border border-slate-100 dark:border-slate-700 text-slate-500 dark:text-slate-400' : '') }}"
                        @if($dayAppointments->isNotEmpty()) @mouseenter="tooltip = {{ $d }}" @mouseleave="tooltip = null" @endif
                        title="{{ $isPast ? 'Past date — view only' : 'Add an appointment on ' . \Carbon\Carbon::parse($cellDate)->format('M j') }}">
                        <span class="font-semibold {{ $isToday ? 'text-brand-700 dark:text-brand-300' : '' }}">{{ $d }}</span>
                        @foreach($dayAppointments->take(2) as $appt)
                            <div class="mt-1 text-[9px] rounded px-1 truncate flex items-center gap-0.5 {{ $isPast ? 'bg-slate-300 dark:bg-slate-700 text-slate-600 dark:text-slate-400' : 'bg-brand-600 text-white' }}">
                                <i data-lucide="user" class="w-2 h-2 flex-shrink-0"></i>{{ $appt->client_name }}
                            </div>
                        @endforeach

                        @if($dayAppointments->isNotEmpty())
                        <div x-show="tooltip === {{ $d }}" x-transition style="display:none;"
                             class="absolute z-10 left-1/2 -translate-x-1/2 top-full mt-2 w-56 bg-ink text-white text-xs rounded-lg p-3 shadow-xl text-left">
                            <p class="font-semibold mb-1 flex items-center gap-1"><i data-lucide="calendar" class="w-3 h-3"></i>{{ \Carbon\Carbon::parse($cellDate)->format('M j') }} @if($isPast)<span class="text-slate-400 font-normal">(past)</span>@endif</p>
                            @foreach($dayAppointments as $appt)
                                <div class="border-l-2 border-brand-500 pl-2 mb-1.5 last:mb-0">
                                    <p class="font-medium flex items-center gap-1"><i data-lucide="user" class="w-3 h-3"></i>{{ $appt->client_name }} — {{ $appt->service }}</p>
                                    <p class="text-slate-400 flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3"></i>{{ \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') }} @if($appt->location) · {{ $appt->location }} @endif</p>
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </{{ $tag }}>
                @endfor
            </div>
        </div>

        <div class="space-y-4">
            <div class="bg-ink rounded-xl p-5 text-white">
                <p class="text-xs uppercase tracking-wide text-slate-400 mb-3 flex items-center gap-1.5"><i data-lucide="clock" class="w-3.5 h-3.5"></i>Today's Schedule</p>
                @forelse($today as $appt)
                    <div class="border-l-2 border-brand-500 pl-3 text-sm mb-3 last:mb-0">
                        <p class="font-medium flex items-center gap-1.5"><i data-lucide="camera" class="w-3.5 h-3.5"></i>{{ $appt->service }} — {{ $appt->client_name }}</p>
                        <p class="text-xs text-slate-400">{{ \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') }} @if($appt->location) · {{ $appt->location }} @endif</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">No appointments scheduled for today.</p>
                @endforelse
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-1.5"><i data-lucide="calendar-clock" class="w-3.5 h-3.5"></i>Upcoming Schedule</p>
                @php
                    $upcoming = $appointments
                        ->where('appointment_date', '>', now()->toDateString())
                        ->sortBy('appointment_date')
                        ->take(5);
                @endphp
                @forelse($upcoming as $appt)
                    <div class="flex items-start justify-between gap-2 border-l-2 border-slate-300 dark:border-slate-600 pl-3 text-sm mb-3 last:mb-0">
                        <div>
                            <p class="font-medium text-ink dark:text-white flex items-center gap-1.5"><i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>{{ $appt->client_name }} — {{ $appt->service }}</p>
                            <p class="text-xs text-slate-400">{{ $appt->appointment_date->format('M j') }}, {{ \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') }} @if($appt->location) · {{ $appt->location }} @endif</p>
                        </div>
                        <div class="flex-shrink-0 flex items-center gap-2">
                            <button onclick='openEditModal({{ json_encode($appt->id) }})' class="text-brand-600 hover:text-brand-700" title="Edit">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </button>
                            <button onclick='openDeleteConfirm({{ json_encode($appt->id) }}, {{ json_encode($appt->client_name) }})' class="text-red-500 hover:text-red-600" title="Delete">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">Nothing else scheduled.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- LIST VIEW --}}
    <div id="list-view" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden" style="display:none;">
        <div class="overflow-x-auto">
<table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Client</th><th>Service</th><th>Date</th><th>Time</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($appointments as $appt)
                    @php
                        $badge = match($appt->status) {
                            'confirmed' => 'bg-emerald-100 text-emerald-700',
                            'done' => 'bg-slate-100 text-slate-600',
                            'cancelled' => 'bg-red-100 text-red-700',
                            default => 'bg-amber-100 text-amber-700',
                        };
                    @endphp
                    <tr class="border-b border-slate-50 dark:border-slate-700">
                        <td class="p-4 font-medium text-ink dark:text-white flex items-center gap-2"><i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>{{ $appt->client_name }}</td>
                        <td>{{ $appt->service }}</td>
                        <td class="text-slate-500 dark:text-slate-400">{{ $appt->appointment_date->format('M j, Y') }}</td>
                        <td class="text-slate-500 dark:text-slate-400">{{ \Carbon\Carbon::parse($appt->appointment_time)->format('g:i A') }}</td>
                        <td><span class="px-2 py-0.5 rounded-full text-xs {{ $badge }}">{{ ucfirst($appt->status) }}</span></td>
                        <td class="whitespace-nowrap">
                            <button onclick='openEditModal({{ json_encode($appt->id) }})'
                                class="inline-flex items-center gap-1 text-xs bg-brand-50 dark:bg-brand-600/20 text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-600/30 rounded-lg px-2.5 py-1.5 mr-1.5">
                                <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                            </button>
                            <button onclick='openDeleteConfirm({{ json_encode($appt->id) }}, {{ json_encode($appt->client_name) }})'
                                class="inline-flex items-center gap-1 text-xs bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg px-2.5 py-1.5">
                                <i data-lucide="trash-2" class="w-3 h-3"></i> Delete
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-8 text-center text-sm text-slate-400">No appointments yet.</td></tr>
                @endforelse
            </tbody>
        </table>
</div>
    </div>

    {{-- CREATE MODAL --}}
    <div x-show="creating !== null" style="display:none;" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="creating = null">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto" x-show="creating !== null" x-transition.scale>
            <div class="flex items-center justify-between mb-4">
                <p class="font-semibold text-ink dark:text-white flex items-center gap-2"><i data-lucide="calendar-plus" class="w-4 h-4"></i> New Appointment</p>
                <button @click="creating = null" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <form action="{{ route('appointments.store') }}" method="POST" class="space-y-3">
                @csrf
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Client Info</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Full Name <span class="text-red-500">*</span></label>
                        <input type="text" name="client_name" x-model="creating.client_name" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Contact Number <span class="text-red-500">*</span></label>
                        <input type="text" name="contact_number" x-model="creating.contact_number" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
                    <input type="email" name="email" x-model="creating.email" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 pt-2">Schedule</p>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Service <span class="text-red-500">*</span></label>
                    <select name="service" x-model="creating.service" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                        <option value="">Select a service</option>
                        <option>Prenup Shoot</option><option>Graduation Package</option><option>Passport Photo</option>
                        <option>Event Coverage</option><option>Studio Portrait</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Date <span class="text-red-500">*</span></label>
                        <input type="date" name="appointment_date" x-model="creating.appointment_date" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Time <span class="text-red-500">*</span></label>
                        <input type="time" name="appointment_time" x-model="creating.appointment_time" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Location / Studio</label>
                    <input type="text" name="location" x-model="creating.location" placeholder="e.g. Studio A" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>

                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 pt-2">Summary</p>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Additional Notes</label>
                    <textarea name="notes" x-model="creating.notes" rows="2" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="creating = null" class="text-sm text-slate-500 dark:text-slate-400 px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg px-5 py-2">Save Appointment</button>
                </div>
            </form>
        </div>
    </div>

    {{-- EDIT MODAL --}}
    <div x-show="editing !== null" style="display:none;" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="editing = null">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto" x-show="editing !== null" x-transition.scale>
            <div class="flex items-center justify-between mb-4">
                <p class="font-semibold text-ink dark:text-white flex items-center gap-2"><i data-lucide="pencil" class="w-4 h-4"></i> Edit Appointment</p>
                <button @click="editing = null" class="text-slate-400"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>

            <form :action="editing ? '{{ url('/appointments') }}/' + editing.id : '#'" method="POST" class="space-y-3">
                @csrf
                @method('PATCH')
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Full Name</label>
                        <input type="text" name="client_name" x-model="editing.client_name" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Contact Number</label>
                        <input type="text" name="contact_number" x-model="editing.contact_number" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
                    <input type="email" name="email" x-model="editing.email" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Service</label>
                    <select name="service" x-model="editing.service" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                        <option value="">Select a service</option>
                        <option>Prenup Shoot</option><option>Graduation Package</option><option>Passport Photo</option>
                        <option>Event Coverage</option><option>Studio Portrait</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Date</label>
                        <input type="date" name="appointment_date" x-model="editing.appointment_date" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Time</label>
                        <input type="time" name="appointment_time" x-model="editing.appointment_time" required class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Location</label>
                    <input type="text" name="location" x-model="editing.location" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Status</label>
                    <select name="status" x-model="editing.status" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                        <option value="pending">Pending</option><option value="confirmed">Confirmed</option>
                        <option value="done">Done</option><option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Notes</label>
                    <textarea name="notes" x-model="editing.notes" rows="2" class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="editing = null" class="text-sm text-slate-500 dark:text-slate-400 px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-lg px-5 py-2">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    {{-- STYLED DELETE CONFIRMATION MODAL --}}
    <div x-show="confirmingDelete !== null" style="display:none;" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40" @click.self="confirmingDelete = null">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm p-6 text-center" x-show="confirmingDelete !== null" x-transition.scale>
            <div class="w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mx-auto mb-4">
                <i data-lucide="trash-2" class="w-5 h-5 text-red-500"></i>
            </div>
            <p class="font-semibold text-ink dark:text-white mb-1">Delete this appointment?</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">
                <span x-text="confirmingDelete?.name"></span>'s appointment will be moved to the Archive, not permanently deleted — you can restore it later.
            </p>
            <div class="flex gap-2">
                <button @click="confirmingDelete = null" class="flex-1 border border-slate-300 dark:border-slate-600 text-sm rounded-lg py-2 text-slate-600 dark:text-slate-300">Cancel</button>
                <button @click="submitDelete()" class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg py-2">Delete</button>
            </div>
        </div>
    </div>

    {{-- Hidden archive (delete) forms, one per appointment --}}
    @foreach($appointments as $appt)
        <form id="archive-form-{{ $appt->id }}" method="POST" action="{{ route('appointments.destroy', $appt) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endforeach
</div>

<script>
const appointmentsData = JSON.parse(document.getElementById('appointments-data').value);

function setAppointmentsView(view) {
    document.getElementById('calendar-view').style.display = view === 'calendar' ? 'grid' : 'none';
    document.getElementById('list-view').style.display = view === 'list' ? 'block' : 'none';

    const activeCls = ['bg-white', 'dark:bg-slate-600', 'shadow-sm', 'font-medium', 'dark:text-white'];
    const inactiveCls = ['text-slate-500', 'dark:text-slate-300'];
    const calBtn = document.getElementById('btn-view-calendar');
    const listBtn = document.getElementById('btn-view-list');
    const [activeBtn, inactiveBtn] = view === 'calendar' ? [calBtn, listBtn] : [listBtn, calBtn];
    activeBtn.classList.add(...activeCls);
    activeBtn.classList.remove(...inactiveCls);
    inactiveBtn.classList.remove(...activeCls);
    inactiveBtn.classList.add(...inactiveCls);
}

function getRoot() {
    return document.querySelector('[x-data*="editing"]');
}

function startCreate(date) {
    const root = getRoot();
    if (root && window.Alpine) {
        window.Alpine.$data(root).creating = {
            client_name: '', contact_number: '', email: '',
            service: '', appointment_date: date || '', appointment_time: '',
            location: '', notes: ''
        };
    }
}

function openEditModal(id) {
    const root = getRoot();
    const data = appointmentsData.find(a => a.id === id);
    if (root && data && window.Alpine) {
        window.Alpine.$data(root).editing = { ...data };
    }
}

function openDeleteConfirm(id, name) {
    const root = getRoot();
    if (root && window.Alpine) {
        window.Alpine.$data(root).confirmingDelete = { id, name };
    }
}

function submitDelete() {
    const root = getRoot();
    if (root && window.Alpine) {
        const id = window.Alpine.$data(root).confirmingDelete?.id;
        if (id) document.getElementById('archive-form-' + id).submit();
    }
}
</script>
@endsection
