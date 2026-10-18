<?php
namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use App\Services\Catalog\ProductEditor;
use App\Services\Inventory\BackorderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    public function __construct(private ProductEditor $products)
    {
    }

    public function index(Request $request)
    {
        // A board's cut sizes count toward its stock status (Material::getStockStatusAttribute).
        // Sizes in the shop's order (its price list's).
        $query = Product::with(['variants' => fn ($q) => $q->inShopOrder(), 'variants.materials.cutSizes', 'category'])
            ->where('status', '!=', 'archived');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->inShopOrder()->paginate(15)->withQueryString();
        // Back links (Categories, Archive) return to this list as it was: its search, category and page.
        $request->session()->put('inventory.list_url', $request->fullUrl());
        $categories = Category::whereNull('archived_at')->inShopOrder()->get();
        $archivedCount = Product::where('status', 'archived')->count();
        // "Made from" choices. Whole boards and rolls aren't: a product uses their standard pieces
        // ("Sintra Board - Long"), which cut the next board themselves when they run out.
        $materials = \App\Models\Material::whereNull('archived_at')->whereNull('sheet_width')->orderBy('name')->get();
        // Products added or changed by cashiers, waiting for an owner (a cashier: their own).
        $waitingCount = ApprovalRequest::pending()->whereIn('type', ['product.create', 'product.update'])
            ->when($request->user()->role !== 'admin', fn ($q) => $q->where('requested_by', $request->user()->id))->count();
        // Add Product suggests SKUs from the names as the server makes them: each category's prefix,
        // and the SKUs already used (archived ones too), so its suggestion is a free one.
        $skuPrefixes = $categories->mapWithKeys(fn ($c) => [(string) $c->id => $this->products->categoryPrefix($c->id)])->filter();
        $takenSkus = [
            'products' => Product::pluck('sku')->map(fn ($s) => strtoupper($s))->values(),
            'sizes' => ProductVariant::pluck('sku')->filter()->map(fn ($s) => strtoupper($s))->values(),
        ];

        return view('inventory.index', compact('products', 'categories', 'archivedCount', 'materials', 'waitingCount', 'skuPrefixes', 'takenSkus'));
    }

    // Add Product now lives inline on the index page as a popout panel —
    // no separate create page needed anymore.

    // An owner's new product is saved. A cashier's is checked the same way (so mistakes show at
    // once) and goes to an owner for approval: nothing is added until it's approved.
    public function store(Request $request)
    {
        $input = $this->products->normalizeNew($request->except(['_token', '_form']));
        $validated = $this->products->validateNew($input);

        if ($request->user()->role !== 'admin') {
            ApprovalRequest::create([
                'type' => 'product.create',
                'requested_by' => $request->user()->id,
                'summary' => "Add product '{$validated['name']}'",
                'payload' => $input,
            ]);

            return redirect()->route('inventory.index')->with('success', "'{$validated['name']}' was sent to the owner for approval. It's added to Products once it's approved: see Approvals.");
        }

        $product = $this->products->create($validated);

        return redirect()->route('inventory.index')->with('success', "Product '{$product->name}' created.");
    }

    // The edit page: the product, its sizes and their prices, and what each size is made of. A
    // cashier's changes go to an owner for approval (update).
    public function edit(Request $request, Product $product)
    {
        $product->load(['variants' => fn ($q) => $q->inShopOrder(), 'variants.materials']);
        $cutIds = $product->variants->filter(fn ($v) => $this->products->isCut($v))->pluck('id')->values();
        // A cashier's changes to this product still waiting for an owner: sending again replaces them.
        $waiting = $request->user()->role === 'admin' ? null : ApprovalRequest::pending()
            ->where('type', 'product.update')->where('product_id', $product->id)->where('requested_by', $request->user()->id)->latest()->first();

        // A save that was refused comes back with what was typed; else the product as it is.
        $rows = old('variants') !== null
            ? collect(old('variants'))->values()->map(fn ($r) => [
                'id' => isset($r['id']) && $r['id'] !== '' ? (int) $r['id'] : null,
                'variant_name' => $r['variant_name'] ?? '',
                'sku' => $r['sku'] ?? '',
                'price' => $r['price'] ?? '',
                'low_stock_threshold' => $r['low_stock_threshold'] ?? 5,
                'active' => ! empty($r['active']),
                'remove' => ! empty($r['remove']),
                'materials' => array_values($r['materials'] ?? []),
            ])
            : $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'variant_name' => $v->variant_name,
                'sku' => $v->sku,
                'price' => $v->price !== null ? (float) $v->price : '',
                'low_stock_threshold' => $v->low_stock_threshold,
                'active' => $v->status === 'active',
                'remove' => false,
                'materials' => $cutIds->contains($v->id) ? [] : $v->materials->map(fn ($m) => [
                    'material_id' => $m->id, 'quantity_per_unit' => (float) $m->pivot->quantity_per_unit,
                ])->values()->all(),
            ]);

        // "Made from" choices, as on Add Product, plus any the sizes already use (even archived).
        $usedIds = $product->variants->reject(fn ($v) => $cutIds->contains($v->id))->flatMap->materials->pluck('id');
        $materials = Material::whereNull('sheet_width')
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $usedIds))
            ->orderBy('name')->get(['id', 'name', 'unit', 'inventory_type', 'archived_at']);
        $categories = Category::whereNull('archived_at')->orWhere('id', $product->category_id)->inShopOrder()->get();
        // Boards or rolls the sizes are cut from or printed on (a board's sizes are set on its card).
        $cutFrom = $product->variants->flatMap->materials->filter->isSheet()->unique('id')->values();

        return view('inventory.edit', compact('product', 'rows', 'cutIds', 'cutFrom', 'materials', 'categories', 'waiting'));
    }

    // An owner's changes are saved. A cashier's are checked the same way and go to an owner for
    // approval; sending again replaces their earlier changes to the same product still waiting.
    public function update(Request $request, Product $product)
    {
        $input = $this->products->normalizeChanges($request->except(['_token', '_method']));
        $validated = $this->products->validateChanges($product, $input);

        if ($request->user()->role !== 'admin') {
            $changes = $this->products->describeChanges($product, $input);
            if (! $changes) {
                return back()->with('error', 'Nothing was changed, so nothing was sent.');
            }
            ApprovalRequest::updateOrCreate(
                ['type' => 'product.update', 'status' => 'pending', 'product_id' => $product->id, 'requested_by' => $request->user()->id],
                ['summary' => "Change '{$product->name}'", 'payload' => $input]
            );

            return redirect()->to(session('inventory.list_url', route('inventory.index')))
                ->with('success', "Your changes to {$product->name} were sent to the owner for approval. Nothing changes until it's approved: see Approvals.");
        }

        $result = $this->products->update($product, $validated);

        $message = "Saved {$product->name}.";
        if ($result['hidden']) {
            $message .= ' Hidden instead of removed, since they have sales: ' . implode(', ', $result['hidden']) . '.';
        }
        if ($result['count_started']) {
            $message .= ' Its stock count starts at 0: add what you have with the + in Restock.';
        }

        return redirect()->to(session('inventory.list_url', route('inventory.index')))->with('success', $message);
    }

    // Restock or manually adjust a single variant. Stock added goes first to items sold while it
    // was out (on order).
    public function adjustStock(Request $request, ProductVariant $variant, BackorderService $backorders)
    {
        $validated = $request->validate([
            'type' => 'required|in:stock_in,adjustment',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:255',
        ]);

        $signedQty = $validated['type'] === 'stock_in' ? $validated['quantity'] : $validated['quantity'];
        // For manual "adjustment" that means correcting DOWN, flip sign via a separate field in real UI;
        // kept simple here: stock_in always adds.
        if ($request->input('direction') === 'out') {
            $signedQty = -$validated['quantity'];
        }

        // Making stocked items uses up what they're made from: short of it, nothing is added and the
        // page says why (it used to be a server error).
        try {
            $variant->adjustStock(
                $signedQty,
                $validated['type'],
                'manual',
                null,
                $validated['remarks'] ?? null,
                Auth::id()
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', "Stock not added to {$variant->variant_name}. " . $e->getMessage());
        }

        return back()->with('success', "Stock updated for {$variant->variant_name}." . ($signedQty > 0 ? $backorders->fillAfterRestock(Auth::id()) : ''));
    }

    // Marks units as damaged — pulled out of sellable count without
    // touching stock_quantity itself, so the audit trail (how many ever
    // came in) stays intact.
    public function markDamaged(Request $request, ProductVariant $variant)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:255',
        ]);

        try {
            $variant->markDamaged($validated['quantity'], $validated['remarks'] ?? null, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$validated['quantity']} unit(s) of {$variant->variant_name} marked damaged.");
    }

    // "Delete" archives the product instead of removing it — nothing is
    // ever permanently lost, it just leaves the main list.
    public function destroy(Product $product)
    {
        $product->update(['status' => 'archived', 'archived_at' => now()]);
        return back()->with('success', "'{$product->name}' archived.");
    }

    public function restore(Product $product)
    {
        $product->update(['status' => 'active', 'archived_at' => null]);
        return back()->with('success', "'{$product->name}' restored.");
    }

    public function archive()
    {
        $archivedProducts = Product::with('category')->where('status', 'archived')->orderByDesc('archived_at')->get();
        return view('inventory.archive', compact('archivedProducts'));
    }

    // Low stock report, grouped by product — now checks SELLABLE stock
    // (stock minus damaged), not raw stock, so a product that's only
    // "low" because half its units are damaged still shows up here.
    public function lowStock()
    {
        $variants = ProductVariant::with('product')
            ->whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->orderByRaw('stock_quantity - damaged_quantity')
            ->get();

        return view('inventory.low-stock', compact('variants'));
    }
}
