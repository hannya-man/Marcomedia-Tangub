<?php
namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Sales\SalePayments;
use Illuminate\Http\Request;

// A payment on what a sale still owes, taken on the sale's page (from Sales, or a customer's balance).
class SalePaymentController extends Controller
{
    public function store(Request $request, Sale $sale, SalePayments $payments)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:9999999',
            'method' => 'required|in:cash,gcash',
            'reference' => 'nullable|string|max:100',
        ], [
            'amount.required' => 'Enter the amount received.',
            'amount.min' => 'Enter the amount received.',
        ]);

        try {
            $payment = $payments->record($sale, (float) $data['amount'], $data['method'], $data['reference'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $sale->refresh();
        $left = max((float) $sale->total_amount - (float) $sale->amount_paid, 0);
        $change = round((float) $data['amount'] - (float) $payment->amount, 2);

        return back()->with('success', '₱' . number_format($payment->amount, 2) . " {$payment->methodLabel()} payment recorded for {$sale->invoice_number}"
            . ($sale->customer ? " ({$sale->customer->name})" : '') . '. '
            . ($left > 0 ? '₱' . number_format($left, 2) . ' still owed.' : 'Paid in full.' . ($change > 0 ? ' Change: ₱' . number_format($change, 2) . '.' : '')));
    }
}
