<?php
namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\Inventory\BackorderService;
use App\Services\Inventory\SheetYield;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rings up one sale: its lines, the payment at the counter (SalePayments) and the stock it takes.
 * Billing/POS uses it (POSController::store), and so do sales imported from a sheet (SaleImport).
 */
class SaleRecorder
{
    public function __construct(private SalePayments $payments, private BackorderService $backorders)
    {
    }

    /**
     * $data is POSController::store's validated input: items (product_id, product_variant_id, quantity,
     * unit_price, customization_details, width, height), customer_id or new_customer_name/phone,
     * payment_method, payment_reference, amount_paid, discount_amount.
     *
     * @return array{0: Sale, 1: int, 2: Collection} the sale, how many pieces went on order, and the
     *                                               stocked sizes it left at 0
     */
    public function ring(array $data, User $by): array
    {
        return DB::transaction(function () use ($data, $by) {
            $subtotal = 0;
            $lineItems = [];

            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $variant = ! empty($item['product_variant_id']) ? ProductVariant::findOrFail($item['product_variant_id']) : null;
                if ($variant && $variant->product_id !== $product->id) {
                    throw new \RuntimeException("{$variant->variant_name} is not a size of {$product->name}. Remove it from the cart and add it again.");
                }

                // Cut from boards or rolls: the size it's charged by, in feet. A standard size
                // has its own; a custom size comes from the cart.
                $size = $variant ? self::typeSize($variant) : null;
                [$width, $height] = [null, null];
                $variantName = $variant?->variant_name;
                if (is_array($size)) {
                    [$width, $height] = [$size[0] / 12, $size[1] / 12];
                } elseif ($size === 'custom') {
                    if (empty($item['width']) || empty($item['height'])) {
                        throw new \RuntimeException("Enter the width and height for {$product->name} ({$variant->variant_name}).");
                    }
                    [$width, $height] = [(float) $item['width'], (float) $item['height']];
                    $variantName = 'Custom ' . SheetYield::label($width * 12, $height * 12);
                }

                $lineSubtotal = $item['unit_price'] * $item['quantity'];
                $subtotal += $lineSubtotal;
                $lineItems[] = [
                    'product' => $product, 'variant' => $variant, 'variant_name' => $variantName,
                    'width' => $width, 'height' => $height, 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
                    'subtotal' => $lineSubtotal, 'customization_details' => $item['customization_details'] ?? null,
                ];
            }

            $discount = $data['discount_amount'] ?? 0;
            if ($discount > $subtotal) {
                throw new \RuntimeException('The discount can\'t be more than the subtotal (₱' . number_format($subtotal, 2) . ').');
            }
            $total = $subtotal - $discount;
            $amountPaid = $data['amount_paid'];
            if ($amountPaid <= 0) {
                throw new \RuntimeException('Please enter an amount paid (a full payment or a down payment) before completing the sale.');
            }

            // A customer typed in at the counter is saved, so the next sale can pick them
            // from the list. The same name and phone is the same customer.
            $customerId = $data['customer_id'] ?? null;
            if (filled($data['new_customer_name'] ?? null)) {
                $customerId = Customer::firstOrCreate([
                    'name' => trim($data['new_customer_name']),
                    'phone' => filled($data['new_customer_phone'] ?? null) ? trim($data['new_customer_phone']) : null,
                ])->id;
            }

            // Saved unpaid, then the counter payment is taken like every payment after it
            // (SalePayments), so it's the first line of the sale's payment history. Paid in full
            // moves it on to processing (made, then released: "completed"); a down payment keeps
            // it pending, with the balance still owed.
            $sale = Sale::create([
                'invoice_number' => Sale::generateInvoiceNumber(),
                'customer_id' => $customerId,
                'user_id' => $by->id,
                'shift_id' => Shift::openFor($by->id)?->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => 0,
                'total_amount' => $total,
                'amount_paid' => 0,
                'change_amount' => 0,
                'payment_method' => $data['payment_method'],
                'status' => $total > 0 ? 'pending' : 'processing',   // all discount: nothing to pay
                'notes' => $data['notes'] ?? null,
            ]);
            if ($total > 0) {
                $this->payments->record($sale, (float) $amountPaid, $data['payment_method'], $data['payment_reference'] ?? null, $by);
                $sale->refresh();
            }

            $onOrder = 0;
            $tookCount = [];   // stocked sizes that took their count, to warn about any left at 0
            foreach ($lineItems as $line) {
                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product']->id,
                    'product_variant_id' => $line['variant']?->id,
                    'item_name' => $line['product']->name,
                    'variant_name' => $line['variant_name'],
                    'customization_details' => $line['customization_details'],
                    'width' => $line['width'],
                    'height' => $line['height'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount' => 0,
                    'subtotal' => $line['subtotal'],
                ]);

                // Stock is deducted as soon as the sale is placed, even on a down payment: the
                // item is reserved for this customer. A stocked item takes its count (stock minus
                // damaged); a made-to-order size its discrete materials, from the active batch.
                // Out of stock, the line goes on order and takes its stock when it comes in:
                // Marcomedia accepts orders even when an item runs out.
                if ($this->backorders->take($saleItem, $by->id)) {
                    $onOrder += $line['quantity'];
                } elseif ($line['product']->track_inventory && $line['variant']) {
                    $tookCount[] = $line['variant']->id;
                }
            }

            // Taking the last ones leaves a size at 0, never below.
            $emptied = ProductVariant::with('product')->whereIn('id', $tookCount)->get()->filter(fn ($v) => $v->sellable_quantity <= 0);

            return [$sale, $onOrder, $emptied];
        }, 3);   // tried again if it ran into another sale at the same moment (a database deadlock)
    }

    // A type of an item cut from boards or rolls: its size [width, height] in inches, 'custom' for
    // "Custom size", or null for any other type.
    public static function typeSize(ProductVariant $variant)
    {
        $a = $variant->attributes ?? [];
        if (! empty($a['custom'])) {
            return 'custom';
        }

        return isset($a['width_in'], $a['height_in']) ? [(float) $a['width_in'], (float) $a['height_in']] : null;
    }
}
