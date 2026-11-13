<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Services\Sales\SaleImport;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Importing sales from a sheet (owner only), from the Sales Report: the file is checked first and
 * what it would add is shown; then the page sends the sales back a few at a time, so each request
 * stays well inside Vercel's 30 seconds (a sale is about a second online).
 */
class SaleImportController extends Controller
{
    public function __construct(private SaleImport $import)
    {
    }

    public function check(Request $request)
    {
        $request->validate(['file' => 'required|file|max:5120'], ['file.required' => 'Pick the file to import.', 'file.max' => 'That file is over 5 MB.']);
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            return response()->json(['message' => 'Import an Excel file (.xlsx) or a CSV file (.csv).'], 422);
        }

        try {
            $plan = $this->import->plan($this->import->rows($request->file('file')->getRealPath(), $extension), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($plan + ['total' => round(array_sum(array_column($plan['sales'], 'total')), 2)]);
    }

    public function run(Request $request)
    {
        $data = $request->validate([
            'sales' => 'required|array|min:1|max:10',
            'sales.*.invoice' => 'nullable|string|max:60',
            'sales.*.at' => 'required|date',
            'sales.*.staff_id' => 'required|integer|exists:users,id',
            'sales.*.customer' => 'nullable|string|max:150',
            'sales.*.method' => 'required|in:cash,gcash',
            'sales.*.lines' => 'required|array|min:1|max:100',
            'sales.*.lines.*.product_id' => 'required|integer|exists:products,id',
            'sales.*.lines.*.variant_id' => 'nullable|integer|exists:product_variants,id',
            'sales.*.lines.*.quantity' => 'required|integer|min:1|max:10000',
            'sales.*.lines.*.price' => 'required|numeric|min:0.01|max:9999999',
        ]);

        $imported = [];
        $errors = [];
        foreach ($data['sales'] as $sale) {
            try {
                $created = $this->import->run($sale + ['invoice' => null, 'customer' => null]);
                $imported[] = ['invoice' => $created->invoice_number, 'total' => (float) $created->total_amount];
            } catch (RuntimeException $e) {
                $errors[] = ($sale['invoice'] ?? 'A sale') . ': ' . $e->getMessage();
            }
        }
        if ($imported) {
            ActivityLog::record($request->user()->id, 'sales.import', 'Imported ' . count($imported) . ' ' . (count($imported) === 1 ? 'sale' : 'sales')
                . ' from a sheet: ' . implode(', ', array_column($imported, 'invoice')), true);
        }

        return response()->json(['imported' => $imported, 'errors' => $errors]);
    }

    // A file to fill in: the Items sold columns, two example rows of a real item, and how to fill it in.
    public function template()
    {
        $product = Product::with(['variants' => fn ($q) => $q->where('status', 'active')])->where('status', 'active')->orderBy('name')->first();
        $variant = $product?->variants->first();
        $example = fn (string $time, int $qty) => [now()->toDateString(), $time, 'PAPER-001', auth()->user()->name, 'Juan Dela Cruz',
            $product?->name ?? 'Your item', $variant?->variant_name ?? '', $variant?->sku ?? $product?->sku ?? '', $qty, (float) ($variant?->price ?? $product?->base_price ?? 100), 'Cash'];

        return Xlsx::download('sales-import-template.xlsx', [
            'Items sold' => [SaleImport::COLUMNS, $example('9:30 AM', 2), $example('9:30 AM', 1)],
            'How to fill this in' => [
                ['How to import sales'],
                ['One row per item sold. Rows with the same Invoice are one sale (without an Invoice: the same Date, Time, Staff, Customer and Paid by).'],
                ['Date and Item, Quantity and Unit price are needed. Item can be its SKU instead (from the Products page); Size or type is needed when the item has more than one.'],
                ['Staff is the name of the person who sold it, as in User Management (left blank: you). Paid by is Cash or GCash. Each sale is taken as paid in full.'],
                ['Stock is taken like a sale at the counter. A sale whose Invoice is already in the system is skipped, so the Items sold sheet of a downloaded report imports nothing new.'],
                ['Then on the Sales Report press Import, pick this file, check what it will add, and press Import.'],
            ],
        ]);
    }
}
