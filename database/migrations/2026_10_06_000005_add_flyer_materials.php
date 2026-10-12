<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The materials the flyer items are made from, and what each item uses (its recipe).
//
// Discrete (Discrete Materials page): blanks, parts and paper, counted one by one. A recipe
// lists only amounts that are the same every time: one blank per item, one sheet per printed
// page or photo. Selling a made-to-order item takes these from the active batch, so an item
// can't be sold while its blank is out: Restock the real count first.
// Continuous (Continuous Raw Materials page): inks, film, powder and rolls. How much a job uses
// depends on its design and size, so staff log it with Pull for use.
// Boards and rolls of a set size (sintra board, acrylic, tarpaulin) are listed "by size" on the
// items cut from them; 2026_10_06_000006 turns them into fixed yields of standard pieces.
//
// Everything new starts with no stock and no cost: Restock each with what is on hand.
// Also: Sublimation Poly Fabric (sold by the meter) moves to Continuous, like other fabric rolls,
// and Sublimation Ink comes back from the archive, with no stock until it is restocked.
return new class extends Migration {
    // [name, unit, reorder point]
    private const DISCRETE = [
        ['Magic Mug Blank (11oz)', 'pcs', 5], ['Frosted Glass Mug Blank (11oz)', 'pcs', 5],
        ['Clear Glass Mug Blank (11oz)', 'pcs', 5], ['Glass Beer Mug Blank', 'pcs', 5],
        ['Foldable Fan Blank', 'pcs', 5], ['Ref Magnet Blank', 'pcs', 5], ['Keychain Blank', 'pcs', 5],
        ['Bottle Opener Blank', 'pcs', 5], ['Magic Mirror Blank', 'pcs', 5], ['Table Nametag Blank', 'pcs', 5],
        ['Name Plate Blank', 'pcs', 5], ['Badge Pin Blank', 'pcs', 5], ['Button Pin Set (58mm)', 'pcs', 10],
        ['Umbrella Blank', 'pcs', 5], ['Mousepad Blank', 'pcs', 5], ['Gaming Mousepad Blank', 'pcs', 5],
        ['Cellphone Case Blank', 'pcs', 5], ['Ceramic Plate Blank', 'pcs', 5], ['Photo Rock Blank', 'pcs', 5],
        ['Glass Clock Blank', 'pcs', 5], ['Pillow Case Blank', 'pcs', 5], ['Pillow Insert', 'pcs', 5],
        ['Totebag Blank', 'pcs', 5], ['Eco Bag Blank', 'pcs', 10], ['Wallet Blank', 'pcs', 5],
        ['Notebook Blank', 'pcs', 5], ['Pen Blank', 'pcs', 10], ['Pencil Blank', 'pcs', 10],
        ['Planner Blank', 'pcs', 5], ['Cap Blank', 'pcs', 5], ['Handkerchief Blank', 'pcs', 5],
        ['Lanyard Blank (with hook)', 'pcs', 10], ['Trophy Blank', 'pcs', 3], ['Plaque Blank', 'pcs', 3],
        ['Medal Blank', 'pcs', 10], ['Medal Ribbon', 'pcs', 10], ['Sash Blank', 'pcs', 5],
        ['Photo Glass Blank', 'pcs', 3], ['Picture Frame', 'pcs', 5], ['Wall Frame Blank', 'pcs', 3],
        ['Stand Frame Blank', 'pcs', 3], ['3D Frame Blank', 'pcs', 3],
        ['Bond Paper (Short)', 'sheets', 100], ['Bond Paper (A4)', 'sheets', 100], ['Bond Paper (Long)', 'sheets', 100],
        ['Photo Paper (4R)', 'sheets', 50], ['Photo Paper (A4)', 'sheets', 50], ['Glossy Paper (A4)', 'sheets', 50],
        ['Card Stock (A4)', 'sheets', 50], ['Sticker Paper (A4)', 'sheets', 50],
    ];

    // [name, unit, reorder point, sheet size in ft (cut by size, one batch per sheet) or null]
    private const CONTINUOUS = [
        ['Sublimation Paper Roll', 'meters', 10, null], ['DTF Film Roll', 'meters', 5, null],
        ['DTF Ink', 'ml', 200, null], ['DTF Powder', 'grams', 200, null], ['Eco-Solvent Ink', 'ml', 200, null],
        ['Printer Ink', 'ml', 100, null], ['Printable Vinyl Roll', 'sq ft', 50, null],
        ['Satin Ribbon', 'meters', 10, null], ['Acrylic Sheet (3mm)', 'sq ft', 32, [4, 8]],
        // Tarpaulin comes in rolls of a set width, 50 m (164 ft) long. Reorder at one roll.
        ['Tarpaulin Roll 3 ft', 'sq ft', 492, [3, 164]], ['Tarpaulin Roll 4 ft', 'sq ft', 656, [4, 164]],
        ['Tarpaulin Roll 5 ft', 'sq ft', 820, [5, 164]], ['Tarpaulin Roll 6 ft', 'sq ft', 984, [6, 164]],
    ];

    // product => [type or '*' for every type => [[material, amount, how]]]. 'by size' = per sq ft of the job.
    private const RECIPES = [
        'Mug' => [
            'Regular 11oz' => [['Ceramic Mug Blank (11oz)', 1]], 'Regular 15oz' => [['Ceramic Mug Blank (15oz)', 1]],
            'Magic' => [['Magic Mug Blank (11oz)', 1]], 'Frosted' => [['Frosted Glass Mug Blank (11oz)', 1]],
            'Transparent' => [['Clear Glass Mug Blank (11oz)', 1]], 'Beer Mug' => [['Glass Beer Mug Blank', 1]],
        ],
        'Tumbler' => ['20oz' => [['Stainless Tumbler Blank (20oz)', 1]], '30oz' => [['Stainless Tumbler Blank (30oz)', 1]]],
        'Foldable Fan' => ['*' => [['Foldable Fan Blank', 1]]],
        'Ref Magnet' => ['*' => [['Ref Magnet Blank', 1]]],
        'Keychain' => ['*' => [['Keychain Blank', 1]]],
        'Bottle Opener' => ['*' => [['Bottle Opener Blank', 1]]],
        'Magic Mirror' => ['*' => [['Magic Mirror Blank', 1]]],
        'Table Nametag' => ['*' => [['Table Nametag Blank', 1]]],
        'Name Plate' => ['*' => [['Name Plate Blank', 1]]],
        'Badge Pin' => ['*' => [['Badge Pin Blank', 1]]],
        'Button Pin' => ['*' => [['Button Pin Set (58mm)', 1]]],
        'Umbrella' => ['*' => [['Umbrella Blank', 1]]],
        'Mousepad' => ['Regular' => [['Mousepad Blank', 1]], 'Gaming' => [['Gaming Mousepad Blank', 1]]],
        'Cellphone Case' => ['*' => [['Cellphone Case Blank', 1]]],
        'Plate' => ['*' => [['Ceramic Plate Blank', 1]]],
        'Rock Photo' => ['*' => [['Photo Rock Blank', 1]]],
        'Glass Clock' => ['*' => [['Glass Clock Blank', 1]]],
        'Pillow' => ['*' => [['Pillow Case Blank', 1], ['Pillow Insert', 1]]],
        'Totebag' => ['*' => [['Totebag Blank', 1]]],
        'Eco Bag' => ['*' => [['Eco Bag Blank', 1]]],
        'Wallet' => ['*' => [['Wallet Blank', 1]]],
        'Notebook' => ['*' => [['Notebook Blank', 1]]],
        'Pen' => ['*' => [['Pen Blank', 1]]],
        'Pencil' => ['*' => [['Pencil Blank', 1]]],
        'Planner' => ['*' => [['Planner Blank', 1]]],
        'Cap' => ['*' => [['Cap Blank', 1]]],
        'Handkerchief' => ['*' => [['Handkerchief Blank', 1]]],
        'Lanyard' => ['*' => [['Lanyard Blank (with hook)', 1]]],
        'PVC ID' => ['*' => [['PVC Card Blank', 1], ['ID Card Ribbon (YMCKO)', 1], ['Lamination Film', 1]]],
        'Lamination' => ['*' => [['Lamination Film', 1]]],
        'Trophy' => ['*' => [['Trophy Blank', 1]]],
        'Plaque' => ['*' => [['Plaque Blank', 1]]],
        'Medal' => ['*' => [['Medal Blank', 1], ['Medal Ribbon', 1]]],
        'Sash' => ['*' => [['Sash Blank', 1]]],
        'Document Printing' => ['Short' => [['Bond Paper (Short)', 1]], 'A4' => [['Bond Paper (A4)', 1]], 'Long' => [['Bond Paper (Long)', 1]]],
        'Photo Printing' => ['4R' => [['Photo Paper (4R)', 1]], 'A4' => [['Photo Paper (A4)', 1]]],
        'Frame' => ['*' => [['Picture Frame', 1]]],
        'Sintra Board' => ['*' => [['Sintra Board', 1, 'by size']]],
        'Glass' => ['*' => [['Photo Glass Blank', 1]]],
        'Acrylic' => ['*' => [['Acrylic Sheet (3mm)', 1, 'by size']]],
        'Wall Frame' => ['*' => [['Wall Frame Blank', 1]]],
        'Stand Frame' => ['*' => [['Stand Frame Blank', 1]]],
        '3D Frame' => ['*' => [['3D Frame Blank', 1]]],
        // Printed on whichever roll width fits the job best (2026_10_06_000006).
        'Tarpaulin' => ['*' => [['Tarpaulin Roll 3 ft', 1, 'by size'], ['Tarpaulin Roll 4 ft', 1, 'by size'],
            ['Tarpaulin Roll 5 ft', 1, 'by size'], ['Tarpaulin Roll 6 ft', 1, 'by size']]],
    ];

    public function up(): void {
        $now = now();
        foreach (self::DISCRETE as [$name, $unit, $reorder]) {
            $this->material($name, $unit, 'discrete', $reorder, null, $now);
        }
        foreach (self::CONTINUOUS as [$name, $unit, $reorder, $sheet]) {
            $this->material($name, $unit, 'continuous', $reorder, $sheet, $now);
        }

        // Fabric is sold by the meter: a continuous roll, logged with Pull for use.
        DB::table('materials')->where('name', 'Sublimation Poly Fabric - White')->update(['inventory_type' => 'continuous', 'updated_at' => $now]);

        // Every sublimation item uses this ink. Its old total had no batches behind it, so it comes
        // back at what its batches hold (none): Restock it with what is on hand.
        $ink = DB::table('materials')->where('name', 'Sublimation Ink')->whereNotNull('archived_at')->first();
        if ($ink) {
            DB::table('materials')->where('id', $ink->id)->update([
                'archived_at' => null,
                'stock_quantity' => DB::table('inventory_batches')->where('material_id', $ink->id)
                    ->whereIn('status', ['open', 'unopened'])->sum('remaining_quantity'),
                'updated_at' => $now,
            ]);
        }

        foreach ($this->recipeLines() as [$typeId, $materialId, $amount, $how]) {
            if (DB::table('variant_materials')->where('product_variant_id', $typeId)->where('material_id', $materialId)->exists()) {
                continue;   // already listed, maybe with the shop's own amount
            }
            DB::table('variant_materials')->insert([
                'product_variant_id' => $typeId, 'material_id' => $materialId, 'quantity_per_unit' => $amount,
                'consumption_type' => $how, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void {
        $names = array_merge(array_column(self::DISCRETE, 0), array_column(self::CONTINUOUS, 0));
        $ids = DB::table('materials')->whereIn('name', $names)->pluck('id');

        // Rolling back must never wipe stock history (batches cascade with the material).
        $stocked = DB::table('inventory_batches')->whereIn('material_id', $ids)->distinct()->count('material_id');
        if ($stocked > 0) {
            throw new RuntimeException("{$stocked} of the flyer materials have stock history. Archive them instead of rolling back.");
        }

        // Recipe lines on the shop's original materials, except the ones the test data already had.
        $original = ['Ceramic Mug Blank (11oz)', 'Ceramic Mug Blank (15oz)', 'Stainless Tumbler Blank (20oz)',
            'Stainless Tumbler Blank (30oz)', 'PVC Card Blank', 'ID Card Ribbon (YMCKO)'];
        foreach ($this->recipeLines() as [$typeId, $materialId, , , $product, $material]) {
            if (! in_array($material, $original, true) && ! ($product === 'PVC ID' && $material === 'Lamination Film')) {
                DB::table('variant_materials')->where('product_variant_id', $typeId)->where('material_id', $materialId)->delete();
            }
        }

        DB::table('materials')->whereIn('id', $ids)->delete();   // their recipe lines go with them
        DB::table('materials')->where('name', 'Sublimation Poly Fabric - White')->update(['inventory_type' => 'discrete']);
        // Sublimation Ink stays restored: archive it on the Continuous page if it isn't used.
    }

    private function material(string $name, string $unit, string $type, float $reorder, ?array $sheet, $now): void {
        if (DB::table('materials')->where('name', $name)->exists()) {
            return;
        }

        DB::table('materials')->insert([
            'name' => $name,
            'code' => $this->freeCode(App\Models\Material::suggestCode($name)),
            'unit' => $unit,
            'inventory_type' => $type,
            'sheet_width' => $sheet[0] ?? null,
            'sheet_height' => $sheet[1] ?? null,
            'pack_size' => $sheet ? $sheet[0] * $sheet[1] : null,
            'stock_quantity' => 0,
            'low_stock_threshold' => $reorder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Same rule as BatchInventoryService::ensureCode: PB, then PB2, PB3...
    private function freeCode(string $base): string {
        $code = $base;
        for ($i = 2; DB::table('materials')->where('code', $code)->exists(); $i++) {
            $code = substr($base, 0, 10 - strlen((string) $i)) . $i;
        }
        return $code;
    }

    // [type id, material id, amount, how, product, material] for every recipe line whose product
    // type and material both exist.
    private function recipeLines(): array {
        $lines = [];
        foreach (self::RECIPES as $product => $byType) {
            $productId = DB::table('products')->where('name', $product)->where('status', '!=', 'archived')->value('id');
            if (! $productId) {
                continue;
            }
            $types = DB::table('product_variants')->where('product_id', $productId)->pluck('id', 'variant_name');
            foreach ($byType as $type => $materials) {
                foreach ($type === '*' ? $types : [$type => $types[$type] ?? null] as $typeId) {
                    foreach ($materials as $m) {
                        $materialId = DB::table('materials')->where('name', $m[0])->value('id');
                        if ($typeId && $materialId) {
                            $lines[] = [$typeId, $materialId, $m[1], ($m[2] ?? null) === 'by size' ? 'per_area' : 'fixed', $product, $m[0]];
                        }
                    }
                }
            }
        }
        return $lines;
    }
};
