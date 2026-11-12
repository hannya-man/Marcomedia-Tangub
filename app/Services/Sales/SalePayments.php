<?php
namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Money taken for a sale: the payment at the counter, and each one after it on what's still owed (a
 * down payment paid off days later). Each is kept on its own (SalePayment) with how it was paid, who
 * took it and the shift they were on, so a sale shows its own payment history; the sale's total paid
 * goes up with it. Cash can be more than what's owed, and the rest is change. A GCash transfer can't
 * be: there's no cash at the counter to give back.
 */
class SalePayments
{
    public function record(Sale $sale, float $received, string $method, ?string $reference, User $by): SalePayment
    {
        return DB::transaction(function () use ($sale, $received, $method, $reference, $by) {
            // Locked: two payments at the same moment can't both take the same balance.
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! in_array($sale->status, ['pending', 'processing'], true)) {
                throw new \RuntimeException("{$sale->invoice_number} is " . ucfirst($sale->status) . '. Payments are only taken on open sales.');
            }

            $owed = round((float) $sale->total_amount - (float) $sale->amount_paid, 2);
            $received = round($received, 2);
            if ($owed <= 0) {
                throw new \RuntimeException("{$sale->invoice_number} is already paid in full.");
            }
            if ($received <= 0) {
                throw new \RuntimeException('Enter the amount received.');
            }
            $paid = min($received, $owed);
            $change = round($received - $paid, 2);
            if ($change > 0 && $method !== 'cash') {
                $owedText = '₱' . number_format($owed, 2);
                throw new \RuntimeException("A GCash payment can't be more than the {$owedText} owed. Enter {$owedText}, and send the rest back.");
            }

            $payment = $sale->payments()->create([
                'amount' => $paid,
                'method' => $method,
                'reference' => filled($reference) ? trim($reference) : null,
                'note' => $change > 0 ? 'Cash received ₱' . number_format($received, 2) . ', change ₱' . number_format($change, 2) : null,
                'received_by' => $by->id,
                'shift_id' => Shift::openFor($by->id)?->id,
            ]);

            // Like at the counter: amount_paid is all that was handed over, change_amount what went back.
            $sale->amount_paid = round((float) $sale->amount_paid + $received, 2);
            $sale->change_amount = round((float) $sale->change_amount + $change, 2);
            // Paid in full: a down payment moves on to processing (being made, then released).
            if ($sale->status === 'pending' && $paid >= $owed) {
                $sale->status = 'processing';
            }
            $sale->save();

            return $payment;
        });
    }
}
