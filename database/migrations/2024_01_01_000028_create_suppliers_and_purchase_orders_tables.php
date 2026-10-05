<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Suppliers in CDO and Pagadian. lead_time_days = how long a delivery
        // usually takes after ordering. Used to suggest reorder points.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('city', 100)->nullable();              // "Cagayan de Oro", "Pagadian"
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedTinyInteger('lead_time_days')->default(3);
            $table->string('notes', 255)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 30)->unique();              // PO-2026-0001
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->enum('status', ['ordered', 'partial', 'received', 'cancelled'])->default('ordered');
            $table->date('ordered_at');
            $table->date('expected_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'status']);
        });

        // One row per material on a PO. A monthly Central Board bundle of
        // 5 packs x 100 sheets is ONE row: packs_ordered = 5, qty_per_pack = 100.
        // Each pack that arrives becomes its own batch (inventory_batches).
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials');
            $table->unsignedInteger('packs_ordered');
            $table->decimal('qty_per_pack', 10, 3);                // in the material's unit
            $table->decimal('cost_per_pack', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique()->after('name');                 // CB, ACR3, TARP4 (used in batch numbers)
            $table->decimal('pack_size', 10, 3)->nullable()->after('unit');                  // usual qty in one pack, roll, or box
            $table->unsignedSmallInteger('reorder_packs')->nullable()->after('low_stock_threshold');
            $table->foreignId('default_supplier_id')->nullable()->after('reorder_packs')
                ->constrained('suppliers')->nullOnDelete();
        });

        // stock_quantity is now the live total of all unclosed batches, kept in
        // sync by BatchInventoryService. low_stock_threshold is the reorder point.
        // 3 decimals to match inventory_batches. Raw ALTER because the app has no doctrine/dbal.
        DB::statement('ALTER TABLE materials MODIFY stock_quantity DECIMAL(12,3) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE materials MODIFY low_stock_threshold DECIMAL(12,3) NOT NULL DEFAULT 5');
    }

    public function down(): void {
        DB::statement('ALTER TABLE materials MODIFY low_stock_threshold DECIMAL(10,2) NOT NULL DEFAULT 5');
        DB::statement('ALTER TABLE materials MODIFY stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0');

        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_supplier_id');
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'pack_size', 'reorder_packs']);
        });

        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
