<?php
namespace App\Models;

use App\Services\Inventory\InventoryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scrapped / rejected output: a production run that came out wrong because of a
 * machine or process error (misprint, jam, wrong cut).
 *
 * The materials it used up are 'wasted' rows in batch_adjustments, linked here,
 * and material_cost is what they cost. Two things can follow, and both can happen:
 *   Reprinted - sale_id is the customer's invoice. That sale still counts in Sales,
 *               and material_cost comes off its profit.
 *   Sold      - the rejects are still sold as scrap or clearance. The money becomes its
 *               own invoice (scrap_sale_id), so it is in Sales and the cashier's cash
 *               count, but it never counts as profit: the lost material cancels it out.
 */
class RejectedOutput extends Model
{
    protected $fillable = [
        'reference', 'item_name', 'product_variant_id', 'quantity', 'cause', 'reason', 'sale_id',
        'material_cost', 'status', 'scrap_revenue', 'scrap_sale_id', 'scrap_sold_at', 'scrap_note', 'user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'material_cost' => 'decimal:2',
        'scrap_revenue' => 'decimal:2',
        'scrap_sold_at' => 'datetime',
    ];

    public const CAUSES = [
        'machine' => 'Machine error',
        'process' => 'Process error',
        'other' => 'Other',
    ];

    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function sale() { return $this->belongsTo(Sale::class); }   // the customer's job it was reprinted for
    public function scrapSale() { return $this->belongsTo(Sale::class, 'scrap_sale_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function losses() { return $this->hasMany(BatchAdjustment::class); }

    public function getCauseLabelAttribute(): string
    {
        return self::CAUSES[$this->cause] ?? ucfirst((string) $this->cause);
    }

    // Always zero. Scrap revenue only offsets the cost of the lost material.
    public function getProfitAttribute(): float
    {
        return 0.0;
    }

    /**
     * The rejects were still sold. Records a completed sale with one scrap line (no product),
     * so the money shows in Sales and in the seller's shift. Returns that sale.
     */
    public function sellAsScrap(float $revenue, string $paymentMethod, ?string $note, int $userId): Sale
    {
        $revenue = round($revenue, 2);
        if ($revenue <= 0) {
            throw new InventoryException('Enter how much the rejects were sold for. If nothing was paid, discard them instead.');
        }

        return DB::transaction(function () use ($revenue, $paymentMethod, $note, $userId) {
            $rejected = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($rejected->status !== 'rejected') {
                throw new InventoryException("{$rejected->reference} is already " . ($rejected->status === 'scrap_sold' ? 'sold as scrap' : 'discarded') . '.');
            }

            $sale = Sale::create([
                'invoice_number' => Sale::generateInvoiceNumber(),
                'user_id' => $userId,
                'subtotal' => $revenue,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $revenue,
                'amount_paid' => $revenue,
                'change_amount' => 0,
                'payment_method' => $paymentMethod,
                'status' => 'completed',
                'notes' => "Scrap from {$rejected->reference}. Counts as a sale, but ₱0.00 profit.",
            ]);
            $sale->items()->create([
                'product_id' => null,
                'item_name' => Str::limit("Scrap: {$rejected->item_name}", 150),
                'variant_name' => $rejected->reference,
                'customization_details' => $note,
                'quantity' => $rejected->quantity,
                'unit_price' => round($revenue / max($rejected->quantity, 1), 2),
                'discount' => 0,
                'subtotal' => $revenue,
            ]);

            $rejected->update([
                'status' => 'scrap_sold',
                'scrap_revenue' => $revenue,
                'scrap_sale_id' => $sale->id,
                'scrap_sold_at' => now(),
                'scrap_note' => $note,
            ]);
            $this->setRawAttributes($rejected->getAttributes(), true);

            return $sale;
        });
    }
}
