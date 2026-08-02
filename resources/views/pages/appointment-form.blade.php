@extends('layouts.app')
@section('title', isset($appointment) ? 'Edit Appointment' : 'New Appointment')

@section('content')
@php
    $editInitial = isset($appointment) ? [
        'client_name' => $appointment->client_name,
        'contact_number' => $appointment->contact_number,
        'email' => $appointment->email,
        'service' => $appointment->service,
        'appointment_date' => $appointment->appointment_date->format('Y-m-d'),
        'appointment_time' => substr($appointment->appointment_time, 0, 5),
        'location' => $appointment->location,
        'notes' => $appointment->notes,
    ] : null;
@endphp
<div class="max-w-2xl mx-auto"
     x-data="appointmentForm(@json($editInitial), @json($prefillDate ?? null))"
     x-init="trackUnsavedChanges()">

    <button type="button" onclick="confirmBack()"
        class="text-sm font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-4 py-2 flex items-center gap-2 mb-6 shadow-sm">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Appointments
    </button>

    <div class="flex items-center justify-between mb-8">
        <div class="flex flex-col items-center gap-2 flex-1">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold"
                 :class="step >= 1 ? 'bg-brand-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-300'">1</div>
            <p class="text-xs font-medium" :class="step >= 1 ? 'text-ink dark:text-white' : 'text-slate-500 dark:text-slate-400'">Client Info</p>
        </div>
        <div class="h-0.5 flex-1 -mt-6" :class="step >= 2 ? 'bg-brand-600' : 'bg-slate-200 dark:bg-slate-700'"></div>
        <div class="flex flex-col items-center gap-2 flex-1">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold"
                 :class="step >= 2 ? 'bg-brand-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-300'">2</div>
            <p class="text-xs font-medium" :class="step >= 2 ? 'text-ink dark:text-white' : 'text-slate-500 dark:text-slate-400'">Service &amp; Schedule</p>
        </div>
        <div class="h-0.5 flex-1 -mt-6" :class="step >= 3 ? 'bg-brand-600' : 'bg-slate-200 dark:bg-slate-700'"></div>
        <div class="flex flex-col items-center gap-2 flex-1">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold"
                 :class="step >= 3 ? 'bg-brand-600 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-300'">3</div>
            <p class="text-xs font-medium" :class="step >= 3 ? 'text-ink dark:text-white' : 'text-slate-500 dark:text-slate-400'">Summary</p>
        </div>
    </div>

    <form method="POST"
          action="{{ isset($appointment) ? route('appointments.update', $appointment) : route('appointments.store') }}"
          @submit="submitted = true" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        @csrf
        @if(isset($appointment)) @method('PATCH') @endif

        {{-- STEP 1 --}}
        <div x-show="step === 1">
            <p class="font-medium text-ink dark:text-white mb-4">Step 1 — Client Information</p>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" name="client_name" x-model="form.client_name" required
                           class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Contact Number <span class="text-red-500">*</span></label>
                    <input type="text" name="contact_number" x-model="form.contact_number" required
                           class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
            </div>
            <div class="mb-2">
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
                <input type="email" name="email" x-model="form.email"
                       class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            <p x-show="attemptedStep1 && !step1Valid" class="text-xs text-red-500 mb-4">Please fill in name and contact number before continuing.</p>
            <div class="flex justify-end mt-4">
                <button type="button" @click="goToStep(2)" class="bg-brand-600 text-white text-sm rounded-lg px-5 py-2.5">Next: Service &amp; Schedule</button>
            </div>
        </div>

        {{-- STEP 2 --}}
        <div x-show="step === 2" style="display:none;">
            <p class="font-medium text-ink dark:text-white mb-4">Step 2 — Service &amp; Schedule</p>
            <div class="mb-4">
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Service <span class="text-red-500">*</span></label>
                <select name="service" x-model="form.service" required class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    <option value="">Select a service</option>
                    <option>Prenup Shoot</option>
                    <option>Graduation Package</option>
                    <option>Passport Photo</option>
                    <option>Event Coverage</option>
                    <option>Studio Portrait</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-2">
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Date <span class="text-red-500">*</span></label>
                    <input type="date" name="appointment_date" x-model="form.appointment_date" required
                           class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Time <span class="text-red-500">*</span></label>
                    <input type="time" name="appointment_time" x-model="form.appointment_time" required
                           class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                </div>
            </div>

            {{-- Clickable calendar — pick a date visually instead of typing one --}}
            <div class="mb-4 bg-slate-50 dark:bg-slate-700/40 rounded-lg p-3">
                <div class="flex items-center justify-between mb-2">
                    <button type="button" @click="shiftCalendarMonth(-1)" class="w-6 h-6 rounded border border-slate-200 dark:border-slate-600 flex items-center justify-center text-slate-500 dark:text-slate-300">
                        <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i>
                    </button>
                    <p class="text-xs font-medium text-ink dark:text-white" x-text="calendarLabel"></p>
                    <button type="button" @click="shiftCalendarMonth(1)" class="w-6 h-6 rounded border border-slate-200 dark:border-slate-600 flex items-center justify-center text-slate-500 dark:text-slate-300">
                        <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                <div class="grid grid-cols-7 gap-1 text-center text-[10px] text-slate-400 mb-1">
                    <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                </div>
                <div class="grid grid-cols-7 gap-1">
                    <template x-for="blank in calendarOffset" :key="'b'+blank"><div></div></template>
                    <template x-for="day in calendarDays" :key="day.date">
                        <button type="button" @click="form.appointment_date = day.date"
                            class="aspect-square rounded text-xs"
                            :class="form.appointment_date === day.date ? 'bg-brand-600 text-white font-semibold' : 'hover:bg-slate-200 dark:hover:bg-slate-600 text-ink dark:text-white'"
                            x-text="day.label"></button>
                    </template>
                </div>
            </div>
            <div class="mb-2">
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Location / Studio</label>
                <input type="text" name="location" x-model="form.location" placeholder="e.g. Studio A"
                       class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
            </div>
            <p x-show="attemptedStep2 && !step2Valid" class="text-xs text-red-500 mb-4">Please select a service, date, and time before continuing.</p>
            <div class="flex justify-between mt-4">
                <button type="button" @click="step = 1" class="text-sm text-slate-500 dark:text-slate-400 px-5 py-2.5">Back</button>
                <button type="button" @click="goToStep(3)" class="bg-brand-600 text-white text-sm rounded-lg px-5 py-2.5">Next: Summary</button>
            </div>
        </div>

        {{-- STEP 3 --}}
        <div x-show="step === 3" style="display:none;">
            <p class="font-medium text-ink dark:text-white mb-4">Step 3 — Summary</p>
            <div class="space-y-2 text-sm bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4 mb-4">
                <div class="flex justify-between"><span class="text-slate-500">Client</span><span class="text-ink dark:text-white font-medium" x-text="form.client_name"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Contact</span><span class="text-ink dark:text-white font-medium" x-text="form.contact_number"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Service</span><span class="text-ink dark:text-white font-medium" x-text="form.service"></span></div>
                <div class="flex justify-between"><span class="text-slate-500">Date &amp; Time</span><span class="text-ink dark:text-white font-medium" x-text="form.appointment_date + ' ' + form.appointment_time"></span></div>
                <div class="flex justify-between" x-show="form.location"><span class="text-slate-500">Location</span><span class="text-ink dark:text-white font-medium" x-text="form.location"></span></div>
            </div>

            @if(isset($appointment))
            <div class="mb-4">
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Status</label>
                <select name="status" class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                    @foreach(['pending' => 'Pending', 'confirmed' => 'Confirmed', 'done' => 'Done', 'cancelled' => 'Cancelled'] as $value => $label)
                        <option value="{{ $value }}" {{ $appointment->status === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div class="mb-4">
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Additional Notes</label>
                <textarea name="notes" x-model="form.notes" rows="2" class="mt-1 w-full text-sm rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white"></textarea>
            </div>
            <div class="flex justify-between mt-4">
                <button type="button" @click="step = 2" class="text-sm text-slate-500 dark:text-slate-400 px-5 py-2.5">Back</button>
                <button type="submit" class="bg-ink hover:bg-slate-800 text-white text-sm rounded-lg px-5 py-2.5">
                    {{ isset($appointment) ? 'Save Changes' : 'Confirm & Save Appointment' }}
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function appointmentForm(initial, prefillDate) {
    return {
        step: 1,
        attemptedStep1: false,
        attemptedStep2: false,
        submitted: false,
        form: initial || {
            client_name: '', contact_number: '', email: '',
            service: '', appointment_date: prefillDate || '', appointment_time: '', location: '', notes: ''
        },
        calendarMonth: (() => {
            const base = (initial && initial.appointment_date) || prefillDate || new Date().toISOString().slice(0, 10);
            const d = new Date(base + 'T00:00:00');
            return { year: d.getFullYear(), month: d.getMonth() };
        })(),
        get calendarLabel() {
            const d = new Date(this.calendarMonth.year, this.calendarMonth.month, 1);
            return d.toLocaleString('default', { month: 'long', year: 'numeric' });
        },
        get calendarOffset() {
            return new Date(this.calendarMonth.year, this.calendarMonth.month, 1).getDay();
        },
        get calendarDays() {
            const { year, month } = this.calendarMonth;
            const days = new Date(year, month + 1, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            return Array.from({ length: days }, (_, i) => {
                const day = i + 1;
                return { label: day, date: `${year}-${pad(month + 1)}-${pad(day)}` };
            });
        },
        shiftCalendarMonth(delta) {
            let { year, month } = this.calendarMonth;
            month += delta;
            if (month < 0) { month = 11; year--; }
            if (month > 11) { month = 0; year++; }
            this.calendarMonth = { year, month };
        },
        get step1Valid() { return this.form.client_name.trim() !== '' && this.form.contact_number.trim() !== ''; },
        get step2Valid() { return this.form.service !== '' && this.form.appointment_date !== '' && this.form.appointment_time !== ''; },
        get hasProgress() {
            return Object.values(this.form).some(v => v && v.toString().trim() !== '');
        },
        goToStep(target) {
            if (target === 2) {
                this.attemptedStep1 = true;
                if (!this.step1Valid) return;
            }
            if (target === 3) {
                this.attemptedStep2 = true;
                if (!this.step2Valid) return;
            }
            this.step = target;
        },
        trackUnsavedChanges() {
            window.addEventListener('beforeunload', (e) => {
                if (this.hasProgress && !this.submitted) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        }
    }
}

function confirmBack() {
    if (confirm('Leaving now will not save your progress. Are you sure you want to leave?')) {
        window.location.href = '{{ route('appointments.index') }}';
    }
}
</script>
@endsection
