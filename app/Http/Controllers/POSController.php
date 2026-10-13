<?php
namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\InventoryBatch;
use App\Services\Inventory\BackorderService;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\SheetYield;
use App\Services\Sales\SaleVoider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class POSController extends Controller
{
    // The POS screen: product picker + cart, in the shop's order (the flyer's)
    public function index()
    {
        $products = Product::with(['variants' => function ($q) {
            $q->where('status', 'active')->inShopOrder();
        }, 'variants.materials', 'category'])->where('status', 'active')->inShopOrder()->get();

        // How many of each made-to-order type the store's materials can make right now. A sale takes
        // its discrete materials from the store (BatchInventoryService::consumeForSaleItem); one with
        // a material out still sells, on order (BackorderService). The picker says so up front.
        $inStore = InventoryBatch::usable()->inStore()
            ->selectRaw('material_id, SUM(remaining_quantity) as qty')->groupBy('material_id')->pluck('qty', 'material_id');
        $canMake = function (ProductVariant $v) use ($inStore) {
            $limit = null;
            $short = [];
            foreach ($v->materials as $m) {
                if ($m->inventory_type === 'continuous' || ($m->pivot->consumption_type ?? 'fixed') !== 'fixed') {
                    continue;   // logged by hand, never taken by a sale
                }
                $per = (float) $m->pivot->quantity_per_unit;
                $have = $m->archived_at ? 0 : (float) ($inStore[$m->id] ?? 0);
                $units = $per > 0 ? (int) floor($have / $per + 0.0005) : PHP_INT_MAX;
                if ($units < 1) {
                    $short[] = $m->name;
                }
                $limit = $limit === null ? $units : min($limit, $units);
            }
            return [$limit, $short];
        };

        // Items cut from boards or rolls (sintra board, tarpaulin): a size is charged as a standard
        // piece, and how many the store can supply is its cut pieces plus the whole boards it can
        // still cut (BatchInventoryService::chooseCut, which the sale itself uses).
        $inventory = app(BatchInventoryService::class);
        $cutStock = function (Product $p) use ($inventory) {
            $stocks = $p->variants->flatMap->materials->filter->isSheet()->unique('id')->values();
            if ($stocks->isEmpty()) {
                return null;
            }
            $pieces = $inventory->piecesOf($stocks);

            return ['pieces' => $pieces, 'available' => $pieces->mapWithKeys(fn ($piece) => [$piece->id => $inventory->cutAvailable($piece)])->all()];
        };

        $customers = Customer::orderBy('name')->get();
        $categories = \App\Models\Category::whereNull('archived_at')->inShopOrder()->get();

        // What the picker and cart need about each product, by id. Sent as data rather than
        // written into the markup, so a name like "Kid's Shirt" can't break the page's script.
        $catalog = $products->mapWithKeys(function ($p) use ($canMake, $cutStock) {
            $cut = $cutStock($p);
            // stock: how many can be sold from stock now (finished goods on hand, or what the
            // materials can make); null = no limit. More than that goes on order. short: the
            // materials that are out. A size cut from a board or roll has its size (w x h, inches);
            // "Custom size" is entered at the counter.
            $types = $p->has_variants ? $p->variants->mapWithKeys(function ($v) use ($p, $canMake, $cut) {
                [$limit, $short] = $p->track_inventory ? [$v->sellable_quantity, []] : $canMake($v);
                $size = $cut ? $this->typeSize($v) : null;
                if (is_array($size)) {
                    $options = SheetYield::options($cut['pieces'], $size[0], $size[1]);
                    $best = SheetYield::best($options, $cut['available']);
                    $units = $best ? intdiv($best['available'], $best['blocks']) : 0;
                    $limit = $limit === null ? $units : min($limit, $units);
                    if ($units < 1) {
                        $short = array_merge($short, collect($options)->map(fn ($o) => $o['piece']->cutFrom->name)->unique()->all() ?: ['a board it fits']);
                    }
                } elseif ($size === 'custom' && array_sum($cut['available']) < 1) {
                    // Nothing cut and no whole board or roll at all: no size can be made.
                    $limit = 0;
                    $short = array_merge($short, $cut['pieces']->map(fn ($piece) => $piece->cutFrom->name)->unique()->values()->all());
                }

                return [$v->id => [
                    'name' => $v->variant_name,
                    'price' => (float) ($v->price ?? $p->base_price),
                    'stock' => $limit,
                    'short' => $short,
                    'w' => is_array($size) ? $size[0] : null,
                    'h' => is_array($size) ? $size[1] : null,
                    'custom' => $size === 'custom',
                ]];
            })->sortBy(fn ($t) => $t['custom'] ? 1 : 0) : collect();   // "Custom size" last

            return [$p->id => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => (string) ($p->category_id ?? ''),
                'price' => (float) $p->base_price,
                'tracked' => (bool) $p->track_inventory,
                'variants' => $types,
                // Picked first: the first size or type that isn't out.
                'first' => $types->search(fn ($t) => $t['stock'] === null || $t['stock'] > 0) ?: $types->keys()->first(),
                // The standard pieces a custom size can be charged as (cut sizes of its boards or rolls).
                'cut' => $cut ? $cut['pieces']->map(fn ($piece) => [
                    'id' => $piece->id,
                    'label' => $piece->cutLabel(),
                    'board' => $piece->cutFrom->name,
                    'roll' => $piece->cutFrom->isRoll(),
                    'width' => (float) $piece->cutFrom->sheet_width,
                    'w' => (float) $piece->cut_width_in,
                    'h' => (float) $piece->cut_height_in,
                    'available' => $cut['available'][$piece->id] ?? 0,
                ])->values() : [],
                // Search matches the name, category, SKU and sizes: "wedding" finds Invitation Card.
                'search' => mb_strtolower(implode(' ', [
                    $p->name, $p->category->name ?? '', $p->sku, $p->variants->pluck('variant_name')->implode(' '),
                ])),
            ]];
        });

        return view('pos.index', compact('products', 'customers', 'categories', 'catalog'));
    }

    // Process the sale/transaction
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            // Made-to-order items have no set price: the cashier enters it in the cart.
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.customization_details' => 'nullable|string|max:1000',
            // A custom size cut from boards or rolls, in feet.
            'items.*.width' => 'nullable|numeric|min:0.01|max:1000',
            'items.*.height' => 'nullable|numeric|min:0.01|max:1000',
            'customer_id' => 'nullable|exists:customers,id',
            'new_customer_name' => 'nullable|string|max:150',
            'new_customer_phone' => 'nullable|string|max:30',
            'payment_method' => 'required|in:cash,gcash',
            'amount_paid' => 'required|numeric|min:0|max:9999999',
            'discount_amount' => 'nullable|numeric|min:0',
        ], [
            'items.*.unit_price.required' => 'Every item needs a price. Enter it in the cart.',
            'items.*.unit_price.min' => 'Every item needs a price. Enter it in the cart.',
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
                    if ($variant && $variant->product_id !== $product->id) {
                        throw new \RuntimeException("{$variant->variant_name} is not a size of {$product->name}. Remove it from the cart and add it again.");
                    }

                    // Cut from boards or rolls: the size it's charged by, in feet. A standard size
                    // has its own; a custom size comes from the cart.
                    $size = $variant ? $this->typeSize($variant) : null;
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
                        'product' => $product,
                        'variant' => $variant,
                        'variant_name' => $variantName,
                        'width' => $width,
                        'height' => $height,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $lineSubtotal,
                        'customization_details' => $item['customization_details'] ?? null,
                    ];
                }

                $discount = $validated['discount_amount'] ?? 0;
                if ($discount > $subtotal) {
                    throw new \RuntimeException('The discount can\'t be more than the subtotal (₱' . number_format($subtotal, 2) . ').');
                }
                $total = $subtotal - $discount;
                $amountPaid = $validated['amount_paid'];

                if ($amountPaid <= 0) {
                    throw new \RuntimeException('Please enter an amount paid (a full payment or a down payment) before completing the sale.');
                }

                // A customer typed in at the counter is saved, so the next sale can pick them
                // from the list. The same name and phone is the same customer.
                $customerId = $validated['customer_id'] ?? null;
                if (filled($validated['new_customer_name'] ?? null)) {
                    $customerId = Customer::firstOrCreate([
                        'name' => trim($validated['new_customer_name']),
                        'phone' => filled($validated['new_customer_phone'] ?? null) ? trim($validated['new_customer_phone']) : null,
                    ])->id;
                }

                // Payment status drives the sale's workflow status:
                // - partial payment (a down payment) -> pending, balance still owed
                // - paid in full -> processing, waiting for staff to confirm the
                //   item itself is actually done/released before calling it "completed"
                $status = $amountPaid < $total ? 'pending' : 'processing';

                $sale = Sale::create([
                    'invoice_number' => Sale::generateInvoiceNumber(),
                    'customer_id' => $customerId,
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

                $backorders = app(BackorderService::class);
                $onOrder = 0;

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
                    if ($backorders->take($saleItem, Auth::id())) {
                        $onOrder += $line['quantity'];
                    }
                }

                $message = "Sale {$sale->invoice_number} recorded as " . ucfirst($status) . '.';
                if ($onOrder > 0) {
                    $message .= " {$onOrder} " . ($onOrder === 1 ? 'item is' : 'items are') . ' on order: out of stock now, '
                        . ($onOrder === 1 ? 'it takes its' : 'they take their') . ' stock as soon as it comes in.';
                }

                return redirect()->route('pos.receipt', $sale->id)->with('success', $message);
            }, 3);   // tried again if it ran into another sale at the same moment (a database deadlock)
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    // A type of an item cut from boards or rolls: its size [width, height] in inches, 'custom' for
    // "Custom size", or null for any other type.
    private function typeSize(ProductVariant $variant)
    {
        $a = $variant->attributes ?? [];
        if (! empty($a['custom'])) {
            return 'custom';
        }

        return isset($a['width_in'], $a['height_in']) ? [(float) $a['width_in'], (float) $a['height_in']] : null;
    }

    public function receipt(Sale $sale)
    {
        $sale->load('items.cutPiece.cutFrom', 'customer', 'user');
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
        if ($request->query('status') === 'on_order') {
            // Open sales with an item still waiting for its stock.
            $query->whereIn('status', ['pending', 'processing'])->whereHas('itemsOnOrder');
        } elseif ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        // Invoice number, or the customer's name or phone.
        if ($request->filled('search')) {
            $term = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('invoice_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            });
        }

        $sales = $query->latest()->paginate(20)->withQueryString();
        $selectedSale = $request->filled('edit_sale') ? Sale::with('items')->find($request->query('edit_sale')) : null;
        // Sales a cashier asked an owner to void, still waiting (keyed by sale id).
        $voidRequested = ApprovalRequest::pending()->where('type', 'sale.void')
            ->whereIn('sale_id', $sales->pluck('id'))->pluck('sale_id')->flip();

        // Orders as a separate concept from Sales is retired — Billing/POS
        // already creates a full Sale record for every transaction, so a
        // second lightweight "Order" was just duplicating that. The Order
        // model/table stays in place only so any pre-existing records are
        // still visible in the Archive, not actively created anymore.
        $archivedSalesCount = Sale::where('status', 'archived')->count();
        $archivedOrdersCount = Order::where('status', 'archived')->count();

        return view('pos.transactions', compact(
            'sales', 'selectedSale', 'archivedSalesCount', 'archivedOrdersCount', 'voidRequested'
        ));
    }

    // Void a sale (only pending/processing sales — a fully completed/released
    // sale needs a return process, not a void) and restore stock.
    // An owner voids the sale. A cashier asks an owner to (Approvals), saying why: nothing is voided
    // until it's approved.
    public function void(Request $request, Sale $sale, SaleVoider $voider)
    {
        if (! $voider->canVoid($sale)) {
            return back()->with('error', 'Only pending or processing sales can be voided.');
        }

        if ($request->user()->role !== 'admin') {
            $request->validate(['reason' => 'required|string|max:500'], [
                'reason.required' => 'Say why it should be voided, so the owner can decide.',
            ]);
            if (ApprovalRequest::pending()->where('type', 'sale.void')->where('sale_id', $sale->id)->exists()) {
                return back()->with('error', "{$sale->invoice_number} is already waiting for the owner to approve voiding it.");
            }
            ApprovalRequest::create([
                'type' => 'sale.void',
                'requested_by' => $request->user()->id,
                'sale_id' => $sale->id,
                'summary' => "Void {$sale->invoice_number}",
                'reason' => $request->input('reason'),
            ]);

            return back()->with('success', "Asked the owner to void {$sale->invoice_number}. It stays as it is until it's approved: see Approvals.");
        }

        $filled = $voider->void($sale, $request->user());

        return back()->with('success', "Sale {$sale->invoice_number} voided, stock restored." . $filled);
    }

    // Manual step: staff confirm the item itself is actually done/released,
    // separate from payment being complete.
    public function complete(Sale $sale)
    {
        if ($sale->status !== 'processing') {
            return back()->with('error', 'Only fully-paid (processing) sales can be marked completed.');
        }
        if ($waiting = $this->stillOnOrder($sale)) {
            return back()->with('error', $waiting);
        }
        $sale->update(['status' => 'completed']);
        return back()->with('success', "Sale {$sale->invoice_number} marked completed.");
    }

    // Items on order whose stock has come in take it now. Restocks do this by themselves; this is
    // for stock that came in some other way, like a count.
    public function takeStock(Sale $sale)
    {
        if (! in_array($sale->status, ['pending', 'processing'])) {
            return back()->with('error', "{$sale->invoice_number} is " . ucfirst($sale->status) . '. Only open sales take stock.');
        }

        [$filled, $short] = app(BackorderService::class)->fill(Auth::id(), $sale);
        if ($short) {
            return back()->with('error', ($filled->isNotEmpty() ? "{$filled->count()} item(s) of {$sale->invoice_number} took their stock. " : '')
                . "Still on order: " . implode(' ', array_unique($short)) . ' Restock it, and the order takes it.');
        }

        return back()->with('success', $filled->isEmpty()
            ? "Nothing of {$sale->invoice_number} is on order."
            : "{$sale->invoice_number}: the items on order took their stock and can be made now.");
    }

    // A sale isn't done while an item is on order: it hasn't been made. Items whose stock has come
    // in take it first. Null when nothing is left on order, else why.
    private function stillOnOrder(Sale $sale): ?string
    {
        if (! $sale->itemsOnOrder()->exists()) {
            return null;
        }

        [, $short] = app(BackorderService::class)->fill(Auth::id(), $sale);
        if (! $sale->itemsOnOrder()->exists()) {
            return null;
        }

        return "{$sale->invoice_number} still has items on order, so it can't be completed yet. "
            . ($short ? implode(' ', array_unique($short)) . ' ' : '') . 'Restock it, and the order takes it.';
    }

    // Manual "Delete" from the Sales list — archives instead of removing,
    // same pattern as Products/Appointments/Materials/Categories. This is
    // separate from the automatic 2-year sweep in autoArchiveOld().
    // Owner only. Keeps what the sale was, so Restore puts it back as it was.
    public function archiveOne(Sale $sale)
    {
        if ($sale->status === 'archived') {
            return back()->with('error', "Sale {$sale->invoice_number} is already archived.");
        }
        $sale->update(['archived_from' => $sale->status, 'status' => 'archived', 'archived_at' => now()]);
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

        if ($sale->status === 'archived') {
            return back()->with('error', "{$sale->invoice_number} is archived. Restore it from the Archive first.");
        }
        // Voided is final: the void gave the stock back, and changing the status wouldn't take it again.
        if ($sale->status === 'voided') {
            return $validated['status'] === 'voided'
                ? redirect()->route('sales.index')
                : back()->with('error', "{$sale->invoice_number} is voided. Ring it up again as a new sale instead.");
        }
        // Voiding through Edit goes through Void, so the stock comes back (setting the status alone
        // left the materials used up). A cashier asks an owner with the Void button instead.
        if ($validated['status'] === 'voided') {
            return $request->user()->role === 'admin'
                ? $this->void($request, $sale, app(SaleVoider::class))
                : back()->with('error', "Use the red Void button to ask the owner to void {$sale->invoice_number}.");
        }

        $paid = round((float) $sale->amount_paid + (float) ($validated['additional_payment'] ?? 0), 2);
        $due = round((float) $sale->total_amount - $paid, 2);
        // Paying off the balance moves a down payment on to processing.
        if ($due <= 0 && $validated['status'] === 'pending') {
            $validated['status'] = 'processing';
        }
        // Processing and completed mean paid in full.
        if ($due > 0 && in_array($validated['status'], ['processing', 'completed'])) {
            return back()->with('error', "{$sale->invoice_number} still has ₱" . number_format($due, 2)
                . ' to pay. Record the payment first, or keep it Pending.');
        }
        if ($validated['status'] === 'completed' && $sale->status !== 'completed' && ($waiting = $this->stillOnOrder($sale))) {
            return back()->with('error', $waiting);
        }

        $sale->amount_paid = $paid;
        $sale->change_amount = max($paid - (float) $sale->total_amount, 0);
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

    // Back to what it was before it was archived: a voided sale comes back voided, not as revenue.
    // One archived before that was kept comes back completed, as it always did.
    public function restoreSale(Sale $sale)
    {
        if ($sale->status !== 'archived') {
            return back()->with('error', "Sale {$sale->invoice_number} isn't archived.");
        }
        $sale->update(['status' => $sale->archived_from ?: 'completed', 'archived_from' => null, 'archived_at' => null]);
        return back()->with('success', "Sale {$sale->invoice_number} restored as " . ucfirst($sale->status) . '.');
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
        // archived_from first: MySQL sets columns left to right, so it reads the status before it changes.
        Sale::where('created_at', '<', $cutoff)->where('status', '!=', 'archived')
            ->update(['archived_from' => DB::raw('status'), 'status' => 'archived', 'archived_at' => now()]);
        Order::where('created_at', '<', $cutoff)->where('status', '!=', 'archived')->update(['status' => 'archived']);
    }
}
