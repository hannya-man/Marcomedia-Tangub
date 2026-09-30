<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class POSController extends Controller
{
    // The POS screen: product picker + cart
    public function index()
    {
        $products = Product::with(['variants' => function ($q) {
            $q->where('status', 'active');
        }, 'category'])->where('status', 'active')->get();

        $customers = Customer::orderBy('name')->get();
        $categories = \App\Models\Category::whereNull('archived_at')->orderBy('name')->get();

        return view('pos.index', compact('products', 'customers', 'categories'));
    }

    // Process the sale/transaction
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.customization_details' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'required|in:cash,gcash',
            'amount_paid' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                $subtotal = 0;
                $lineItems = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $variant = !empty($item['product_variant_id'])
                        ? ProductVariant::findOrFail($item['product_variant_id'])
                        : null;

                    // Checks sellable_quantity (stock minus damaged), not
                    // raw stock_quantity — a damaged unit can never be sold.
                    if ($product->track_inventory && $variant && $variant->sellable_quantity < $item['quantity']) {
                        throw new \RuntimeException(
                            "Not enough good stock for {$product->name} ({$variant->variant_name}). Only {$variant->sellable_quantity} sellable."
                        );
                    }

                    $lineSubtotal = $item['unit_price'] * $item['quantity'];
                    $subtotal += $lineSubtotal;

                    $lineItems[] = [
                        'product' => $product,
                        'variant' => $variant,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $lineSubtotal,
                        'customization_details' => $item['customization_details'] ?? null,
                    ];
                }

                $discount = $validated['discount_amount'] ?? 0;
                $total = $subtotal - $discount;
                $amountPaid = $validated['amount_paid'];

                if ($amountPaid <= 0) {
                    throw new \RuntimeException('Please enter an amount paid (a full payment or a down payment) before completing the sale.');
                }

                // Payment status drives the sale's workflow status:
                // - partial payment (a down payment) -> pending, balance still owed
                // - paid in full -> processing, waiting for staff to confirm the
                //   item itself is actually done/released before calling it "completed"
                $status = $amountPaid < $total ? 'pending' : 'processing';

                $sale = Sale::create([
                    'invoice_number' => Sale::generateInvoiceNumber(),
                    'customer_id' => $validated['customer_id'] ?? null,
                    'user_id' => Auth::id(),
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'tax_amount' => 0,
                    'total_amount' => $total,
                    'amount_paid' => $amountPaid,
                    'change_amount' => max($amountPaid - $total, 0),
                    'payment_method' => $validated['payment_method'],
                    'status' => $status,
                ]);

                foreach ($lineItems as $line) {
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $line['product']->id,
                        'product_variant_id' => $line['variant']?->id,
                        'item_name' => $line['product']->name,
                        'variant_name' => $line['variant']?->variant_name,
                        'customization_details' => $line['customization_details'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount' => 0,
                        'subtotal' => $line['subtotal'],
                    ]);

                    // Stock is deducted as soon as the sale is placed, even on a
                    // down payment — the item is reserved for this customer.
                    if ($line['product']->track_inventory && $line['variant']) {
                        $line['variant']->adjustStock(
                            -$line['quantity'],
                            'sale',
                            'sale',
                            $sale->id,
                            "Sold via {$sale->invoice_number}",
                            Auth::id()
                        );
                    }
                }

                return redirect()->route('pos.receipt', $sale->id)
                    ->with('success', "Sale {$sale->invoice_number} recorded as " . ucfirst($status) . ".");
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function receipt(Sale $sale)
    {
        $sale->load('items', 'customer', 'user');
        return view('pos.receipt', compact('sale'));
    }

    // Sales history / transactions list — also loads Orders data since
    // this page now combines both Sales and Orders into one view with tabs.
    public function transactions(Request $request)
    {
        $this->autoArchiveOld();

        $query = Sale::with(['user', 'customer', 'items'])->where('status', '!=', 'archived');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $sales = $query->latest()->paginate(20)->withQueryString();
        $selectedSale = $request->filled('edit_sale') ? Sale::with('items')->find($request->query('edit_sale')) : null;

        // Orders as a separate concept from Sales is retired — Billing/POS
        // already creates a full Sale record for every transaction, so a
        // second lightweight "Order" was just duplicating that. The Order
        // model/table stays in place only so any pre-existing records are
        // still visible in the Archive, not actively created anymore.
        $archivedSalesCount = Sale::where('status', 'archived')->count();
        $archivedOrdersCount = Order::where('status', 'archived')->count();

        return view('pos.transactions', compact(
            'sales', 'selectedSale', 'archivedSalesCount', 'archivedOrdersCount'
        ));
    }

    // Void a sale (only pending/processing sales — a fully completed/released
    // sale needs a return process, not a void) and restore stock.
    public function void(Sale $sale)
    {
        if (!in_array($sale->status, ['pending', 'processing'])) {
            return back()->with('error', 'Only pending or processing sales can be voided.');
        }

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                if ($item->variant) {
                    $item->variant->adjustStock(
                        $item->quantity,
                        'void',
                        'sale_void',
                        $sale->id,
                        "Voided sale {$sale->invoice_number}",
                        Auth::id()
                    );
                }
            }
            $sale->update(['status' => 'voided']);
        });

        return back()->with('success', "Sale {$sale->invoice_number} voided, stock restored.");
    }

    // Manual step: staff confirm the item itself is actually done/released,
    // separate from payment being complete.
    public function complete(Sale $sale)
    {
        if ($sale->status !== 'processing') {
            return back()->with('error', 'Only fully-paid (processing) sales can be marked completed.');
        }
        $sale->update(['status' => 'completed']);
        return back()->with('success', "Sale {$sale->invoice_number} marked completed.");
    }

    // Manual "Delete" from the Sales list — archives instead of removing,
    // same pattern as Products/Appointments/Materials/Categories. This is
    // separate from the automatic 2-year sweep in autoArchiveOld().
    public function archiveOne(Sale $sale)
    {
        $sale->update(['status' => 'archived']);
        return back()->with('success', "Sale {$sale->invoice_number} archived.");
    }

    // Edit a sale: update status directly, and/or record an additional
    // payment against a pending (down-payment) sale.
    public function update(Request $request, Sale $sale)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,completed,voided',
            'additional_payment' => 'nullable|numeric|min:0',
        ]);

        if (!empty($validated['additional_payment'])) {
            $sale->amount_paid += $validated['additional_payment'];
            $sale->change_amount = max($sale->amount_paid - $sale->total_amount, 0);
            // Paying off the balance in full bumps a pending sale to processing automatically.
            if ($sale->amount_paid >= $sale->total_amount && $sale->status === 'pending') {
                $validated['status'] = 'processing';
            }
        }

        $sale->status = $validated['status'];
        $sale->save();

        return redirect()->route('sales.index')->with('success', "Sale {$sale->invoice_number} updated.");
    }

    public function archive()
    {
        $this->autoArchiveOld();
        $archivedSales = Sale::with('user', 'customer')->where('status', 'archived')->latest()->get();
        $archivedOrders = Order::where('status', 'archived')->latest()->get();

        return view('pos.archive', compact('archivedSales', 'archivedOrders'));
    }

    public function restoreSale(Sale $sale)
    {
        $sale->update(['status' => 'completed']);
        return back()->with('success', "Sale {$sale->invoice_number} restored.");
    }

    public function restoreOrder(Order $order)
    {
        $order->update(['status' => 'completed']);
        return back()->with('success', "Order {$order->order_number} restored.");
    }

    // Invoices/receipts older than 2 years get swept into the archive
    // automatically whenever the Sales & Orders page loads.
    private function autoArchiveOld(): void
    {
        $cutoff = now()->subYears(2);
        Sale::where('created_at', '<', $cutoff)->where('status', '!=', 'archived')->update(['status' => 'archived']);
        Order::where('created_at', '<', $cutoff)->where('status', '!=', 'archived')->update(['status' => 'archived']);
    }
}
