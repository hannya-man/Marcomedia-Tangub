<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// A person's shift at the counter: started with the cash already in the drawer, ended by counting it.
// Every payment taken meanwhile is tied to the shift, so the drawer should hold the starting cash plus
// the cash payments. The owner sees each shift, its cash count and everything done in it.
class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $shifts = Shift::with('user')->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->query('user')))
            ->latest('opened_at')->latest('id')->paginate(20)->withQueryString();
        $ids = $shifts->pluck('id');
        $sales = Sale::whereIn('shift_id', $ids)->whereNotIn('status', ['voided', 'archived'])->groupBy('shift_id')
            ->selectRaw('shift_id, COUNT(*) as count, SUM(total_amount) as total')->get()->keyBy('shift_id');
        $taken = SalePayment::whereIn('shift_id', $ids)->groupBy('shift_id')
            ->selectRaw("shift_id, SUM(amount) as total, SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) as cash")->get()->keyBy('shift_id');
        $people = User::withTrashed()->whereIn('id', Shift::select('user_id'))->orderBy('name')->get();

        return view('shifts.index', compact('shifts', 'sales', 'taken', 'people'));
    }

    public function show(Shift $shift)
    {
        $shift->load('user');
        $payments = $shift->payments()->with('sale.customer')->orderBy('id')->get();
        $sales = $shift->sales()->whereNotIn('status', ['voided', 'archived'])->get();
        $timeline = ActivityLog::with('user')->where('shift_id', $shift->id)->orderBy('id')->get();
        $expected = $shift->status === 'closed' ? (float) $shift->expected_sales : $shift->expectedCash();

        return view('shifts.show', compact('shift', 'payments', 'sales', 'timeline', 'expected'));
    }

    public function open(Request $request)
    {
        $data = $request->validate(['opening_cash' => 'nullable|numeric|min:0|max:9999999']);
        if (Shift::openFor(Auth::id())) {
            return back()->with('error', 'You already have an open shift.');
        }

        $cash = round((float) ($data['opening_cash'] ?? 0), 2);
        Shift::create(['user_id' => Auth::id(), 'opened_at' => now(), 'opening_cash' => $cash, 'status' => 'open']);

        return back()->with('success', 'Shift started, with ₱' . number_format($cash, 2) . ' in the drawer.');
    }

    // A blind count: the person counts the drawer without being told what it should hold. A difference
    // doesn't keep the shift open (that only made people type the expected amount): it's saved, and the
    // owner sees it under Activity Log, Needs a look.
    public function close(Request $request, Shift $shift)
    {
        $validated = $request->validate(['actual_cash' => 'required|numeric|min:0|max:9999999']);

        // Your own shift only (an owner may close anyone's).
        if ((int) $shift->user_id !== (int) $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }
        if ($shift->status !== 'open') {
            return back()->with('error', 'This shift is already closed.');
        }

        $expected = $shift->expectedCash();
        $counted = round((float) $validated['actual_cash'], 2);
        $variance = round($counted - $expected, 2);
        $shift->update([
            'expected_sales' => $expected, 'actual_cash' => $counted,
            'variance' => $variance, 'status' => 'closed', 'closed_at' => now(),
        ]);

        $off = abs($variance) >= 0.01;
        $request->attributes->set('activity.needs_review', $off);

        return back()->with($off ? 'warning' : 'success', "{$shift->user->name}'s shift ended. The drawer should have ₱" . number_format($expected, 2)
            . ', and ₱' . number_format($counted, 2) . ' was counted: '
            . ($off ? '₱' . number_format(abs($variance), 2) . ($variance < 0 ? ' short' : ' over') . '. The owner will see it.' : 'it matches.'));
    }
}
