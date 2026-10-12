<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The shop's price lists for sintra board and tarpaulin: every size on them, at its price, in the
// price list's order (product_variants.sort_order).
//   Sintra board comes in two thicknesses, each its own 4 x 8 ft board and its own item:
//     Sintra Board 5mm  the board and item the shop already had, renamed. 8 x 11, 12 x 16 and
//                       16 x 24 in (glossy waterproof sticker), and 8 to 30 inches.
//     Sintra Board 3mm  new, with no stock: Restock it on the Discrete Materials page. 8 to 30 inches.
//   A size given as one number ("12 inches") is cut as a square, 12 x 12 in. The sizes the board
//   was cut into before (Long, A4, 2 x 3 ft...) aren't on the price list, so they go, unless
//   something was cut or sold in them. The whole board stays a cut size: a big custom size is
//   charged as one.
//   Tarpaulin: every size on its flyer, 2 x 3 to 6 x 10 ft. The flyer has 6 x 10 ft at P1,320,
//   where the rest of the list (P22 a sq ft + P100) would make it P1,420: kept as printed.
// "Custom size" stays last on each, priced at the counter.
return new class extends Migration {
    private const WAS = 'Sintra Board';         // the board and item, before the two thicknesses
    private const SKU = 'PS-SINTRA-BOARD';

    // Item (and its board) => its sizes: [name, width in, height in, price].
    private const SINTRA = [
        'Sintra Board 5mm' => [
            ['8 x 11 in', 8, 11, 200], ['12 x 16 in', 12, 16, 350], ['16 x 24 in', 16, 24, 750],
            ['8 inches', 8, 8, 650], ['10 inches', 10, 10, 800], ['12 inches', 12, 12, 950],
            ['15 inches', 15, 15, 1200], ['18 inches', 18, 18, 1500], ['20 inches', 20, 20, 2000],
            ['25 inches', 25, 25, 2600], ['30 inches', 30, 30, 3200],
        ],
        'Sintra Board 3mm' => [
            ['8 inches', 8, 8, 550], ['10 inches', 10, 10, 600], ['12 inches', 12, 12, 780],
            ['15 inches', 15, 15, 950], ['18 inches', 18, 18, 1250], ['20 inches', 20, 20, 1400],
            ['25 inches', 25, 25, 1800], ['30 inches', 30, 30, 2500],
        ],
    ];

    // The board's sizes before (2026_10_06_000006), not on the price list: [name, width in, height in, code tag].
    private const OLD_SIZES = [
        ['Long (8.5 x 13 in)', 8.5, 13, 'LONG'], ['Short (8.5 x 11 in)', 8.5, 11, 'SHORT'],
        ['A4 (8.3 x 11.7 in)', 8.27, 11.69, 'A4'], ['A3 (11.7 x 16.5 in)', 11.69, 16.54, 'A3'],
        ['Small (4 x 6 in)', 4, 6, 'SMALL'], ['12 x 18 in', 12, 18, '1218'],
        ['2 x 3 ft', 24, 36, '2X3'], ['Half board (4 x 4 ft)', 48, 48, 'HALF'],
    ];
    private const WHOLE = ['Whole board (4 x 8 ft)', 48, 96, 'WHOLE'];

    // Feet, down each column of the flyer: width => [length => price].
    private const TARPAULIN = [
        2 => [3 => 232, 4 => 276, 5 => 320, 6 => 364, 7 => 408, 8 => 452, 9 => 496, 10 => 540],
        3 => [3 => 298, 4 => 364, 5 => 430, 6 => 496, 7 => 562, 8 => 628, 9 => 694, 10 => 760],
        4 => [3 => 364, 4 => 452, 5 => 540, 6 => 628, 7 => 716, 8 => 804, 9 => 892, 10 => 980],
        5 => [3 => 430, 4 => 540, 5 => 650, 6 => 760, 7 => 870, 8 => 980, 9 => 1090, 10 => 1200],
        6 => [3 => 496, 4 => 628, 5 => 760, 6 => 892, 7 => 1024, 8 => 1156, 9 => 1288, 10 => 1320],
    ];
    private const TARP_WAS = ['2 x 3 ft', '3 x 4 ft', '3 x 5 ft', '4 x 6 ft', '4 x 8 ft', '5 x 10 ft'];   // its sizes before

    public function up(): void {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->nullable()->after('variant_name');
        });
        $now = now();

        // 5mm: the shop's board and item, renamed. Their old sizes go first, under their old names.
        $was = $this->findBoard(self::WAS);
        $wasItem = DB::table('products')->where('status', '!=', 'archived')
            ->where(function ($q) { $q->where('sku', self::SKU)->orWhere('name', self::WAS); })->orderBy('id')->first();
        if ($was) {
            $this->dropOldSizes($was, $wasItem, $now);
        }
        $board5 = $this->board('Sintra Board 5mm', $was, 'SB', $now);
        $item5 = $this->item('Sintra Board 5mm', $wasItem, self::SKU, null, $now);
        $this->sintraSizes($item5, $board5, self::SINTRA['Sintra Board 5mm'], $now);

        // 3mm: a new board and item, listed right after the 5mm.
        $board3 = $this->board('Sintra Board 3mm', null, 'SB3', $now);
        $item3 = $this->item('Sintra Board 3mm', null, self::SKU . '-3MM', $item5, $now);
        $this->sintraSizes($item3, $board3, self::SINTRA['Sintra Board 3mm'], $now);

        // Tarpaulin: printed on whichever roll its sizes already use.
        $tarp = $this->findItem('Tarpaulin');
        $rolls = $tarp ? $this->stocksOf($tarp) : [];
        if ($tarp && $rolls) {
            $order = 0;
            foreach (self::TARPAULIN as $w => $lengths) {
                foreach ($lengths as $h => $price) {
                    $this->type($tarp, $rolls, "{$w} x {$h} ft", ['width_in' => $w * 12, 'height_in' => $h * 12], $price, ++$order, $now);
                }
            }
            $this->type($tarp, $rolls, 'Custom size', ['custom' => true], null, ++$order, $now);
        }
    }

    public function down(): void {
        // Nothing sold or cut is removed: archive it instead of rolling back.
        $now = now();

        // Tarpaulin: back to its six sizes, with no prices.
        if ($tarp = $this->findItem('Tarpaulin')) {
            foreach (DB::table('product_variants')->where('product_id', $tarp->id)->get() as $type) {
                in_array($type->variant_name, array_merge(self::TARP_WAS, ['Custom size']), true)
                    ? DB::table('product_variants')->where('id', $type->id)->update(['price' => null])
                    : $this->removeType($type);
            }
        }

        // 3mm: its item and board go.
        if ($item3 = $this->findItem('Sintra Board 3mm')) {
            if (DB::table('sale_items')->where('product_id', $item3->id)->exists()) {
                throw new RuntimeException('Sintra Board 3mm has been sold. Archive it on the Products page instead of rolling back.');
            }
            DB::table('products')->where('id', $item3->id)->delete();   // its types go with it
            if ($item3->sort_order !== null) {
                DB::table('products')->where('category_id', $item3->category_id)->where('sort_order', '>', $item3->sort_order)->decrement('sort_order');
            }
        }
        if ($board3 = $this->findBoard('Sintra Board 3mm')) {
            $ids = DB::table('materials')->where('cut_from_id', $board3->id)->pluck('id')->push($board3->id);
            if (DB::table('inventory_batches')->whereIn('material_id', $ids)->exists()) {
                throw new RuntimeException('Sintra Board 3mm has stock history. Archive it on the Discrete Materials page instead of rolling back.');
            }
            DB::table('materials')->where('cut_from_id', $board3->id)->delete();
            DB::table('materials')->where('id', $board3->id)->delete();
        }

        // 5mm: back to "Sintra Board", with its old sizes.
        $item = $this->findItem('Sintra Board 5mm');
        if ($item) {
            foreach (self::SINTRA['Sintra Board 5mm'] as [$name]) {
                $type = DB::table('product_variants')->where('product_id', $item->id)->where('variant_name', $name)->first();
                if ($type) {
                    $this->removeType($type);
                }
            }
        }
        if ($board = $this->findBoard('Sintra Board 5mm')) {
            foreach (self::SINTRA['Sintra Board 5mm'] as [, $w, $h]) {
                $piece = $this->findPiece($board, $w, $h);
                if ($piece && DB::table('inventory_batches')->where('material_id', $piece->id)->exists()) {
                    throw new RuntimeException("{$piece->name} has been cut. Remove the size on the Discrete Materials page instead of rolling back.");
                }
                if ($piece) {
                    DB::table('materials')->where('id', $piece->id)->delete();
                }
            }
            $this->renameBoard($board, self::WAS, $now);
            $board = DB::table('materials')->find($board->id);
            foreach (self::OLD_SIZES as [$name, $w, $h, $tag]) {
                $this->piece($board, $name, $w, $h, $tag, $now);
            }
        }
        if ($item) {
            DB::table('products')->where('id', $item->id)->update(['name' => self::WAS, 'updated_at' => $now]);
            if ($board) {
                foreach (array_merge(self::OLD_SIZES, [self::WHOLE]) as [$name, $w, $h]) {
                    $this->type($item, [$board->id], $name, ['width_in' => $w, 'height_in' => $h], null, null, $now);
                }
            }
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }

    private function findBoard(string $name): ?object
    {
        return DB::table('materials')->where('name', $name)->whereNotNull('sheet_width')->orderBy('id')->first();
    }

    private function findItem(string $name): ?object
    {
        return DB::table('products')->where('status', '!=', 'archived')->where('name', $name)->orderBy('id')->first();
    }

    // A cut size of the board, either way round. Archived ones too: a size cut before is brought back.
    private function findPiece(object $board, float $w, float $h): ?object
    {
        return DB::table('materials')->where('cut_from_id', $board->id)
            ->where(function ($q) use ($w, $h) {
                $q->where(function ($q) use ($w, $h) { $q->where('cut_width_in', $w)->where('cut_height_in', $h); })
                    ->orWhere(function ($q) use ($w, $h) { $q->where('cut_width_in', $h)->where('cut_height_in', $w); });
            })
            ->orderByRaw('archived_at IS NOT NULL')->orderBy('id')->first();
    }

    // The board: the one it was (renamed, with its cut sizes), one already called $name, or a new
    // 4 x 8 ft board with no stock.
    private function board(string $name, ?object $was, string $code, $now): object
    {
        $board = $was ?? $this->findBoard($name);
        if ($board) {
            if ($board->name !== $name) {
                $this->renameBoard($board, $name, $now);
            }
            return DB::table('materials')->find($board->id);
        }

        $id = DB::table('materials')->insertGetId([
            'name' => $name,
            'code' => $this->freeCode($code),
            'unit' => 'sq ft',
            'inventory_type' => 'discrete',
            'sheet_width' => 4,
            'sheet_height' => 8,
            'sheet_kind' => 'board',
            'pack_size' => 32,             // sq ft in one board
            'stock_quantity' => 0,
            'low_stock_threshold' => 32,   // alert when less than one board is left
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('materials')->find($id);
    }

    // The board and its cut sizes ("Sintra Board - Long (8.5 x 13 in)") take the new name.
    private function renameBoard(object $board, string $name, $now): void
    {
        DB::table('materials')->where('id', $board->id)->update(['name' => $name, 'updated_at' => $now]);
        foreach (DB::table('materials')->where('cut_from_id', $board->id)->get() as $piece) {
            if (str_starts_with($piece->name, $board->name . ' - ')) {
                DB::table('materials')->where('id', $piece->id)
                    ->update(['name' => $name . substr($piece->name, strlen($board->name)), 'updated_at' => $now]);
            }
        }
    }

    // The item: the one it was (renamed), one already called $name, or a new made-to-order item
    // listed right after $after.
    private function item(string $name, ?object $was, string $sku, ?object $after, $now): object
    {
        $item = $was ?? $this->findItem($name);
        if ($item) {
            DB::table('products')->where('id', $item->id)->update(['name' => $name, 'updated_at' => $now]);
            return DB::table('products')->find($item->id);
        }

        $order = null;
        if ($after && $after->sort_order !== null) {
            DB::table('products')->where('category_id', $after->category_id)->where('sort_order', '>', $after->sort_order)->increment('sort_order');
            $order = $after->sort_order + 1;
        }
        $id = DB::table('products')->insertGetId([
            'category_id' => $after->category_id ?? DB::table('categories')->where('slug', 'printing-other-services')->value('id'),
            'sort_order' => $order,
            'name' => $name,
            'sku' => $this->freeSku('products', $sku),
            'base_price' => 0,
            'has_variants' => true,
            'track_inventory' => false,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('products')->find($id);
    }

    // The item's sizes, in the price list's order, then "Custom size": each one a cut size of the
    // board and a type of the item at its price. The whole board stays a cut size.
    private function sintraSizes(object $item, object $board, array $sizes, $now): void
    {
        $order = 0;
        foreach ($sizes as [$name, $w, $h, $price]) {
            $this->piece($board, "{$w} x {$h} in", $w, $h, "{$w}X{$h}", $now);
            $this->type($item, [$board->id], $name, ['width_in' => $w, 'height_in' => $h], $price, ++$order, $now);
        }
        [$label, $w, $h, $tag] = self::WHOLE;
        $this->piece($board, $label, $w, $h, $tag, $now);
        $this->type($item, [$board->id], 'Custom size', ['custom' => true], null, ++$order, $now);
    }

    // A cut size of the board, unless it has one that size already. w and h in inches.
    private function piece(object $board, string $label, float $w, float $h, string $tag, $now): void
    {
        $piece = $this->findPiece($board, $w, $h);
        if ($piece) {
            DB::table('materials')->where('id', $piece->id)->update(['archived_at' => null, 'updated_at' => $now]);
            return;
        }

        DB::table('materials')->insert([
            'name' => "{$board->name} - {$label}",
            'code' => $this->freeCode(substr($board->code . $tag, 0, 10)),
            'unit' => 'pcs',
            'inventory_type' => 'discrete',
            'stock_quantity' => 0,
            'low_stock_threshold' => 0,
            'cut_from_id' => $board->id,
            'cut_width_in' => $w,
            'cut_height_in' => $h,
            'cut_yield' => $this->yieldOf((float) $board->sheet_width * 12, (float) $board->sheet_height * 12, $w, $h),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // A type of the item, cut from these boards or rolls. One already there keeps its sales and
    // recipe, and gets the price list's price and place.
    private function type(object $item, array $stockIds, string $name, array $size, $price, ?int $order, $now): void
    {
        $values = ['price' => $price, 'sort_order' => $order, 'status' => 'active', 'updated_at' => $now];
        $typeId = DB::table('product_variants')->where('product_id', $item->id)->where('variant_name', $name)->value('id');
        if ($typeId) {
            DB::table('product_variants')->where('id', $typeId)->update($values);
        } else {
            $typeId = DB::table('product_variants')->insertGetId($values + [
                'product_id' => $item->id,
                'variant_name' => $name,
                'sku' => $this->freeSku('product_variants', $item->sku . '-' . strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'))),
                'attributes' => json_encode($size),
                'stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'reorder_point' => 0,
                'created_at' => $now,
            ]);
        }

        foreach ($stockIds as $stockId) {
            if (! DB::table('variant_materials')->where('product_variant_id', $typeId)->where('material_id', $stockId)->exists()) {
                DB::table('variant_materials')->insert([
                    'product_variant_id' => $typeId, 'material_id' => $stockId, 'quantity_per_unit' => 1,
                    'consumption_type' => 'per_area', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    // The boards or rolls an item's sizes are cut from.
    private function stocksOf(object $item): array
    {
        return DB::table('variant_materials')
            ->join('product_variants', 'product_variants.id', '=', 'variant_materials.product_variant_id')
            ->join('materials', 'materials.id', '=', 'variant_materials.material_id')
            ->where('product_variants.product_id', $item->id)->whereNotNull('materials.sheet_width')->whereNull('materials.archived_at')
            ->distinct()->orderBy('materials.id')->pluck('materials.id')->all();
    }

    // The board's sizes before, and the item's types for them and for the whole board.
    private function dropOldSizes(object $board, ?object $item, $now): void
    {
        foreach (self::OLD_SIZES as [$name]) {
            $piece = DB::table('materials')->where('cut_from_id', $board->id)->where('name', "{$board->name} - {$name}")->first();
            // Pieces still in stock stay: a custom size can be charged as them.
            if (! $piece || (float) $piece->stock_quantity > 0) {
                continue;
            }
            $cut = DB::table('inventory_batches')->where('material_id', $piece->id)->exists()
                || DB::table('sale_items')->where('cut_material_id', $piece->id)->exists();
            $cut
                ? DB::table('materials')->where('id', $piece->id)->update(['archived_at' => $now, 'updated_at' => $now])
                : DB::table('materials')->where('id', $piece->id)->delete();
        }

        if (! $item) {
            return;
        }
        foreach (array_merge(array_column(self::OLD_SIZES, 0), [self::WHOLE[0]]) as $name) {
            $type = DB::table('product_variants')->where('product_id', $item->id)->where('variant_name', $name)->first();
            if (! $type) {
                continue;
            }
            // Kept, no longer offered, if anything refers to it; else removed with its recipe lines.
            $this->typeUsed($type->id)
                ? DB::table('product_variants')->where('id', $type->id)->update(['status' => 'inactive', 'updated_at' => $now])
                : DB::table('product_variants')->where('id', $type->id)->delete();
        }
    }

    private function removeType(object $type): void
    {
        if ($this->typeUsed($type->id)) {
            throw new RuntimeException("{$type->variant_name} ({$type->sku}) has been sold or counted. Archive the item instead of rolling back.");
        }
        DB::table('product_variants')->where('id', $type->id)->delete();   // its recipe lines go with it
    }

    private function typeUsed(int $typeId): bool
    {
        return DB::table('sale_items')->where('product_variant_id', $typeId)->exists()
            || DB::table('inventory_movements')->where('product_variant_id', $typeId)->exists()
            || DB::table('physical_counts')->where('product_variant_id', $typeId)->exists()
            || DB::table('rejected_outputs')->where('product_variant_id', $typeId)->exists();
    }

    // How many w x h pieces one W x H board gives, cut in a grid whichever way round fits more.
    // Same rule as App\Services\Inventory\SheetYield::perSheet().
    private function yieldOf(float $W, float $H, float $w, float $h): int
    {
        $fit = fn ($a, $b) => (int) floor($a / $b + 1e-9);
        return max($fit($W, $w) * $fit($H, $h), $fit($W, $h) * $fit($H, $w));
    }

    // Same rule as BatchInventoryService::ensureCode: the code, then CODE2, CODE3...
    private function freeCode(string $base): string
    {
        $code = $base;
        for ($i = 2; DB::table('materials')->where('code', $code)->exists(); $i++) {
            $code = substr($base, 0, 10 - strlen((string) $i)) . $i;
        }
        return $code;
    }

    // The SKU, or SKU-2, SKU-3... if something already uses it.
    private function freeSku(string $table, string $sku): string
    {
        $free = $sku;
        for ($i = 2; DB::table($table)->where('sku', $free)->exists(); $i++) {
            $free = $sku . '-' . $i;
        }
        return $free;
    }
};
