<?php
namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $this->autoArchiveExpired();

        $calendarMonth = \Carbon\Carbon::parse($request->query('month', now()->format('Y-m')) . '-01');

        $appointments = Appointment::where('status', '!=', 'archived')
            ->orderBy('appointment_date')->orderBy('appointment_time')->get();
        $today = $appointments->filter(fn ($a) => $a->appointment_date->isToday());
        $archivedCount = Appointment::where('status', 'archived')->count();

        return view('pages.appointments', compact(
            'appointments', 'today', 'archivedCount', 'calendarMonth'
        ));
    }

    public function archive()
    {
        $this->autoArchiveExpired();
        $archived = Appointment::where('status', 'archived')->orderByDesc('archived_at')->get();
        return view('pages.appointments-archive', compact('archived'));
    }

    public function restore(Appointment $appointment)
    {
        $appointment->update(['status' => 'pending', 'archived_at' => null, 'archived_reason' => null]);
        return back()->with('success', "{$appointment->client_name}'s appointment restored.");
    }

    // New Appointment is now a popout modal on the index page (see below) —
    // this route stays only as a fallback for anyone with the old direct link.
    public function create(Request $request)
    {
        return redirect()->route('appointments.index');
    }

    public function store(Request $request)
    {
        // A new appointment can't be booked for a day that's already
        // passed — past days are view-only (see the calendar in the
        // index view, which won't even offer the "add" action for them).
        // This is the server-side backstop for that same rule.
        $validated = $request->validate([
            'client_name' => 'required|string|max:150',
            'contact_number' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'service' => 'required|string|max:150',
            'appointment_date' => 'required|date|after_or_equal:today',
            'appointment_time' => 'required',
            'location' => 'nullable|string|max:150',
            'notes' => 'nullable|string',
        ]);

        Appointment::create($validated + ['status' => 'pending']);

        return redirect()->route('appointments.index')->with('success', 'Appointment request saved.');
    }

    // Edit happens via a modal on the index page — this just handles the
    // form submission from that modal. Editing an existing (even past)
    // appointment is still allowed — e.g. marking it done/cancelled — the
    // "no past days" rule only applies to creating brand new ones.
    public function update(Request $request, Appointment $appointment)
    {
        $validated = $this->validated($request);
        $validated['status'] = $request->input('status', $appointment->status);

        $appointment->update($validated);

        return redirect()->route('appointments.index')->with('success', 'Appointment updated.');
    }

    // "Delete" archives instead of removing the record, so nothing is lost.
    public function destroy(Appointment $appointment)
    {
        $appointment->update([
            'status' => 'archived',
            'archived_at' => now(),
            'archived_reason' => 'manual',
        ]);

        return back()->with('success', "{$appointment->client_name}'s appointment archived.");
    }

    // Anything whose date has already passed and isn't done/cancelled/archived
    // gets swept into the archive automatically whenever the page loads.
    private function autoArchiveExpired(): void
    {
        Appointment::where('appointment_date', '<', now()->toDateString())
            ->whereNotIn('status', ['done', 'cancelled', 'archived'])
            ->update([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_reason' => 'expired',
            ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'client_name' => 'required|string|max:150',
            'contact_number' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'service' => 'required|string|max:150',
            'appointment_date' => 'required|date',
            'appointment_time' => 'required',
            'location' => 'nullable|string|max:150',
            'notes' => 'nullable|string',
        ]);
    }
}
