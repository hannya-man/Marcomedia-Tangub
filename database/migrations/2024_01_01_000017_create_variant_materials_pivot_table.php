<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Replaces the old single material_id/material_qty_per_unit columns
        // on product_variants — a real product usually consumes MORE than
        // one material per unit (a sublimation shirt uses fabric AND ink,
        // in different amounts, not just one). This pivot table lets a
        // single variant list as many materials as it actually needs.
        Schema::create('variant_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->decimal('quantity_per_unit', 8, 3); // e.g. 1.2 meters of fabric, or 0.05 liters of ink
            $table->timestamps();
        });

        // Migrate any existing single-material links into the new pivot
        // table before dropping the old columns, so nothing already
        // entered gets silently lost.
        if (Schema::hasColumn('product_variants', 'material_id')) {
            $existing = \Illuminate\Support\Facades\DB::table('product_variants')
                ->whereNotNull('material_id')
                ->whereNotNull('material_qty_per_unit')
                ->get(['id', 'material_id', 'material_qty_per_unit']);

            foreach ($existing as $row) {
                \Illuminate\Support\Facades\DB::table('variant_materials')->insert([
                    'product_variant_id' => $row->id,
                    'material_id' => $row->material_id,
                    'quantity_per_unit' => $row->material_qty_per_unit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropConstrainedForeignId('material_id');
                $table->dropColumn('material_qty_per_unit');
            });
        }
    }
    public function down(): void {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->decimal('material_qty_per_unit', 8, 3)->nullable();
        });
        Schema::dropIfExists('variant_materials');
    }
};
