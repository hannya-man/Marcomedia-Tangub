<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Raw materials — the fabric roll, blank trophies, etc. that get
        // consumed to PRODUCE finished stock. Separate from product_variants
        // (which track the finished, sellable item's stock).
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);              // e.g. "Sublimation Poly Fabric - White"
            $table->string('unit', 20);                // e.g. "meters", "yards", "rolls", "pcs"
            $table->decimal('stock_quantity', 10, 2)->default(0);
            $table->decimal('low_stock_threshold', 10, 2)->default(5);
            $table->decimal('cost_per_unit', 10, 2)->nullable();
            $table->timestamps();
        });

        // Links a finished product size/variant to the material it's cut
        // from, and how much one unit of that variant consumes — e.g. a
        // Medium shirt uses 1.2 meters of fabric, a Large uses 1.4 meters.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('product_id')->constrained('materials')->nullOnDelete();
            $table->decimal('material_qty_per_unit', 8, 3)->nullable()->after('material_id');
        });
    }
    public function down(): void {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
            $table->dropColumn('material_qty_per_unit');
        });
        Schema::dropIfExists('materials');
    }
};
