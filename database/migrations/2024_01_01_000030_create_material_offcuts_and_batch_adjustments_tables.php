<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Reusable leftover pieces (acrylic, plywood, tarp ends) kept aside when a
        // batch is closed. They stay linked to the batch they came from, and are
        // NOT counted in materials.stock_quantity, so scraps never hide a real shortage.
        Schema::create('material_offcuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('source_batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->string('label', 100);                          // "12 x 18 in piece"
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('quantity', 10, 3);                    // in the material's unit
            $table->enum('status', ['available', 'used', 'discarded'])->default('available');
            $table->foreignId('used_for_sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();
            $table->index(['material_id', 'status']);
        });

        // Every stock change that is not a sale: breakage, misprints, count
        // corrections, returns from voided jobs, leftovers at close.
        // quantity is signed (minus = removed, plus = added back), so for every batch:
        //   opening_quantity - SUM(consumptions) + SUM(adjustments) = remaining_quantity
        Schema::create('batch_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->enum('type', ['damaged', 'wasted', 'count_correction', 'void_return', 'to_offcut', 'write_off']);
            $table->decimal('quantity', 10, 3);
            $table->decimal('quantity_before', 10, 3);
            $table->decimal('quantity_after', 10, 3);
            $table->string('reason', 255)->nullable();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();         // job that caused it
            $table->foreignId('source_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete(); // void returns: where it came from
            $table->foreignId('material_offcut_id')->nullable()->constrained('material_offcuts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['inventory_batch_id', 'type']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('batch_adjustments');
        Schema::dropIfExists('material_offcuts');
    }
};
