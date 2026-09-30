<?php
namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShiftController extends Controller
{
    public function open()
    {
        $existing = Shift::where('user_id', Auth::id())->where('status', 'open')->first();
        if ($existing) {
            return back()->with('error', 'You already have an open shift.');
        }

        Shift::create(['user_id' => Auth::id(), 'opened_at' => now(), 'status' => 'open']);

        return back()->with('success', 'Shift started.');
    }

    // The hard block: counted cash has to match system sales for this
    // shift's window, or it stays open.
    public function close(Request $request, Shift $shift)
    {
        $validated = $request->validate(['actual_cash' => 'required|numeric|min:0']);

        if ($shift->status !== 'open') {
            return back()->with('error', 'This shift is already closed.');
        }

        $expected = Sale::where('user_id', $shift->user_id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$shift->opened_at, now()])
            ->sum('total_amount');

        $variance = round($validated['actual_cash'] - $expected, 2);

        // abs(...) >= 0.01, not !== 0 — decimal SUM() can come back as
        // 499.999999 instead of 500.00, and an exact-equality check would
        // block a shift that actually matches.
        if (abs($variance) >= 0.01) {
            return back()->with('error',
                "Cannot close. Expected ₱" . number_format($expected, 2) .
                ", counted ₱" . number_format($validated['actual_cash'], 2) .
                ". Off by ₱" . number_format(abs($variance), 2) . "."
            );
        }

        $shift->update([
            'expected_sales' => $expected, 'actual_cash' => $validated['actual_cash'],
            'variance' => $variance, 'status' => 'closed', 'closed_at' => now(),
        ]);

        return redirect()->route('dashboard')->with('success', 'Shift closed. Counts matched.');
    }
}
