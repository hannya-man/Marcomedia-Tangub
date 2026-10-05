<?php
namespace Database\Seeders;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\BatchInventoryService;
use Illuminate\Database\Seeder;

/**
 * Test data for the flyer items: Regular Mug, Sublimation T-Shirt, PVC ID,
 * Tumbler. Each variant lists the exact materials it consumes and how much
 * per unit — matching the real "Add Product" + "Restock" flow, not a
 * shortcut. Run with: php artisan db:seed --class=TestDataSeeder
 *
 * CHANGED for batch inventory: material stock goes in as an opening batch,
 * not a typed-in stock_quantity. Two opening amounts were raised because the
 * starting products below use more than the old seed had, and the new code refuses
 * to go below zero (the old code silently left these negative):
 *   Sublimation Ink:   uses 2,104 ml, had 2,000 (ended at -104 ml)  -> now 3,000 ml
 *   Poly Fabric White: uses 130 m,    had 80    (ended at -50 m)    -> now 150 m
 */
class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $userId = User::where('role', 'admin')->value('id');
        $inventory = app(BatchInventoryService::class);

        // ---------------------------------------------------------
        // 1. RAW MATERIALS
        // Ink is split by process, not lumped into one "ink" row —
        // sublimation ink is shared across mug/shirt/tumbler since
        // they're all sublimation-printed; ID ribbon is separate
        // because dye-sub card printers use a different consumable.
        // ---------------------------------------------------------
        $materials = collect([
            ['name' => 'Ceramic Mug Blank (11oz)',        'unit' => 'pcs',    'opening_stock' => 100, 'low_stock_threshold' => 15, 'cost_per_unit' => 45.00],
            ['name' => 'Ceramic Mug Blank (15oz)',        'unit' => 'pcs',    'opening_stock' => 60,  'low_stock_threshold' => 10, 'cost_per_unit' => 55.00],
            ['name' => 'Sublimation Ink',                 'unit' => 'ml',     'opening_stock' => 3000,'low_stock_threshold' => 300,'cost_per_unit' => 3.50],
            ['name' => 'Sublimation Poly Fabric - White', 'unit' => 'meters', 'opening_stock' => 150, 'low_stock_threshold' => 15, 'cost_per_unit' => 120.00],
            ['name' => 'Sublimation Transfer Paper',      'unit' => 'sheets', 'opening_stock' => 500, 'low_stock_threshold' => 50, 'cost_per_unit' => 8.00],
            ['name' => 'PVC Card Blank',                  'unit' => 'pcs',    'opening_stock' => 300, 'low_stock_threshold' => 40, 'cost_per_unit' => 6.50],
            ['name' => 'ID Card Ribbon (YMCKO)',          'unit' => 'panels', 'opening_stock' => 800, 'low_stock_threshold' => 100,'cost_per_unit' => 2.20],
            ['name' => 'Lamination Film',                 'unit' => 'pcs',    'opening_stock' => 250, 'low_stock_threshold' => 30, 'cost_per_unit' => 3.00],
            ['name' => 'Stainless Tumbler Blank (20oz)',  'unit' => 'pcs',    'opening_stock' => 50,  'low_stock_threshold' => 8,  'cost_per_unit' => 180.00],
            ['name' => 'Stainless Tumbler Blank (30oz)',  'unit' => 'pcs',    'opening_stock' => 40,  'low_stock_threshold' => 8,  'cost_per_unit' => 210.00],
        ])->mapWithKeys(function ($m) use ($inventory, $userId) {
            $opening = $m['opening_stock'];
            unset($m['opening_stock']);

            $material = Material::firstOrCreate(['name' => $m['name']], $m + ['stock_quantity' => 0]);
            if ($material->wasRecentlyCreated) {
                $inventory->recordOpeningStock($material, $opening, 'store', $userId);
            }
            return [$m['name'] => $material->id];
        });

        // ---------------------------------------------------------
        // 2. CATEGORIES
        // ---------------------------------------------------------
        $catMugs   = Category::firstOrCreate(['slug' => 'customized-items'], ['name' => 'Customized Items']);
        $catShirts = Category::firstOrCreate(['slug' => 'apparel-sublimation'], ['name' => 'Apparel / Sublimation']);
        $catIds    = Category::firstOrCreate(['slug' => 'pvc-id-printing'], ['name' => 'PVC ID Printing']);

        // ---------------------------------------------------------
        // 3. PRODUCTS + VARIANTS + MATERIAL CONSUMPTION
        // Each variant is created at zero stock, materials attached
        // with quantity_per_unit, then adjustStock() brings in the
        // starting batch — same call your Restock button uses, so
        // materials get deducted from their active batches.
        // ---------------------------------------------------------

        // --- Regular Mug ---
        $mug = Product::create([
            'category_id' => $catMugs->id, 'name' => 'Regular Mug', 'sku' => 'MUG-REG-001',
            'description' => 'Sublimation-printed ceramic mug.', 'base_price' => 150,
            'has_variants' => true, 'track_inventory' => true, 'status' => 'active',
        ]);
        $this->addVariant($mug, '11oz', 'MUG-REG-001-11', 150, [
            $materials['Ceramic Mug Blank (11oz)'] => 1,      // 1 blank per mug
            $materials['Sublimation Ink'] => 8,                // 8ml ink per mug
        ], 20, $userId);
        $this->addVariant($mug, '15oz', 'MUG-REG-001-15', 180, [
            $materials['Ceramic Mug Blank (15oz)'] => 1,
            $materials['Sublimation Ink'] => 10,
        ], 15, $userId);

        // --- Sublimation T-Shirt ---
        $shirt = Product::create([
            'category_id' => $catShirts->id, 'name' => 'Sublimation T-Shirt', 'sku' => 'TSHIRT-SUB-002',
            'description' => 'Full sublimation printed t-shirt.', 'base_price' => 280,
            'has_variants' => true, 'track_inventory' => true, 'status' => 'active',
        ]);
        foreach ([
            ['Small (S)', 'S', 1.0],
            ['Medium (M)', 'M', 1.2],
            ['Large (L)', 'L', 1.4],
            ['X-Large (XL)', 'XL', 1.6],
        ] as [$label, $code, $fabricMeters]) {
            $this->addVariant($shirt, $label, "TSHIRT-SUB-002-{$code}", 280, [
                $materials['Sublimation Poly Fabric - White'] => $fabricMeters,
                $materials['Sublimation Ink'] => 15,
                $materials['Sublimation Transfer Paper'] => 1,
            ], 25, $userId);
        }

        // --- PVC ID ---
        $id = Product::create([
            'category_id' => $catIds->id, 'name' => 'PVC ID', 'sku' => 'PVCID-001',
            'description' => 'Laminated PVC ID card, dye-sublimation printed.', 'base_price' => 60,
            'has_variants' => true, 'track_inventory' => true, 'status' => 'active',
        ]);
        $this->addVariant($id, 'Standard', 'PVCID-001-STD', 60, [
            $materials['PVC Card Blank'] => 1,
            $materials['ID Card Ribbon (YMCKO)'] => 1,     // 1 panel per card
            $materials['Lamination Film'] => 1,
        ], 100, $userId);

        // --- Tumbler ---
        $tumbler = Product::create([
            'category_id' => $catMugs->id, 'name' => 'Tumbler', 'sku' => 'TUMB-001',
            'description' => 'Sublimation-printed stainless steel tumbler.', 'base_price' => 320,
            'has_variants' => true, 'track_inventory' => true, 'status' => 'active',
        ]);
        $this->addVariant($tumbler, '20oz', 'TUMB-001-20', 320, [
            $materials['Stainless Tumbler Blank (20oz)'] => 1,
            $materials['Sublimation Ink'] => 12,
        ], 12, $userId);
        $this->addVariant($tumbler, '30oz', 'TUMB-001-30', 380, [
            $materials['Stainless Tumbler Blank (30oz)'] => 1,
            $materials['Sublimation Ink'] => 15,
        ], 10, $userId);
    }

    /**
     * Create a variant at zero stock, attach its material recipe, then
     * bring in the starting batch through adjustStock() — the same path
     * InventoryController::store() uses, so materials get consumed exactly
     * like a real product creation would.
     */
    private function addVariant(Product $product, string $name, string $sku, float $price, array $materialsWithQty, int $initialStock, ?int $userId): void
    {
        $variant = $product->variants()->create([
            'variant_name' => $name, 'sku' => $sku, 'price' => $price,
            'stock_quantity' => 0, 'low_stock_threshold' => 5, 'reorder_point' => 10, 'status' => 'active',
        ]);

        foreach ($materialsWithQty as $materialId => $quantityPerUnit) {
            $variant->materials()->attach($materialId, ['quantity_per_unit' => $quantityPerUnit, 'consumption_type' => 'fixed']);
        }

        $variant->adjustStock($initialStock, 'stock_in', 'initial_stock', null, 'Test data seed', $userId);
    }
}
