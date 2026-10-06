<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sintra board: a continuous sheet material (see 2026_10_06_000001). Each 4 x 8 ft sheet is
// 32 sq ft and gets its own batch when restocked. It starts with no stock: Restock it on the
// Continuous Raw Materials page with the real number of sheets and cost per sheet.
// Another thickness (3mm, 5mm) is its own material: add it there with "Cut from whole sheets".
return new class extends Migration {
    private const NAME = 'Sintra Board';

    public function up(): void {
        if (DB::table('materials')->where('name', self::NAME)->exists()) {
            return;
        }

        // SB, or SB2, SB3... if another material already uses the code.
        $code = 'SB';
        for ($i = 2; DB::table('materials')->where('code', $code)->exists(); $i++) {
            $code = 'SB' . $i;
        }

        DB::table('materials')->insert([
            'name' => self::NAME,
            'code' => $code,
            'unit' => 'sq ft',
            'inventory_type' => 'continuous',
            'sheet_width' => 4,
            'sheet_height' => 8,
            'pack_size' => 32,             // sq ft in one sheet
            'stock_quantity' => 0,
            'low_stock_threshold' => 32,   // alert when less than one sheet is left
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void {
        $id = DB::table('materials')->where('name', self::NAME)->value('id');
        if (! $id) {
            return;
        }

        // Rolling back must never wipe stock history (batches cascade with the material).
        $batches = DB::table('inventory_batches')->where('material_id', $id)->count();
        if ($batches > 0) {
            throw new RuntimeException(self::NAME . " has {$batches} batch(es) of stock history. "
                . 'Archive it on the Continuous Raw Materials page instead of rolling back.');
        }

        DB::table('materials')->where('id', $id)->delete();
    }
};
