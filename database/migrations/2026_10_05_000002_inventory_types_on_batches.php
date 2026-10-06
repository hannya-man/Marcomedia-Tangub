<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The four inventory types, all on the batch engine (inventory_batches):
//   Products                   - finished goods in products / product_variants (no change).
//   Continuous Raw Materials   - materials.inventory_type = 'continuous': fabric rolls, thread, liquids.
//                                Never deducted per item. Staff log each pull for use.
//   Discrete Materials         - materials.inventory_type = 'discrete': PVC cards, RFID chips, fasteners.
//                                Counted 1 to 1 and deducted automatically when used.
//   Scrapped / Rejected Output - rejected_outputs: a production run that failed. Its materials are
//                                logged as waste; if sold as scrap, the revenue counts as zero profit.
//
// Also removes the earlier packs tables (material_batches, material_packs, used_materials).
// They were a second batch system next to inventory_batches and only held test data.
return new class extends Migration {
    public function up(): void {
        if (! Schema::hasColumn('materials', 'inventory_type')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->string('inventory_type', 12)->nullable()->after('unit');
            });
        } else {
            DB::statement('ALTER TABLE materials MODIFY inventory_type VARCHAR(12) NULL');
        }

        // Names from the earlier versions.
        DB::table('materials')->where('inventory_type', 'raw')->update(['inventory_type' => 'continuous']);
        DB::table('materials')->where('inventory_type', 'material')->update(['inventory_type' => 'discrete']);
        if (Schema::hasColumn('materials', 'count_type')) {
            DB::table('materials')->whereNull('inventory_type')->where('count_type', 'whole')->update(['inventory_type' => 'continuous']);
            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn('count_type');
            });
        }

        Schema::dropIfExists('used_materials');
        Schema::dropIfExists('pack_movements');
        Schema::dropIfExists('material_packs');
        Schema::dropIfExists('material_batches');

        // Who pulled the material, and why. Set for manual pulls of continuous materials.
        if (! Schema::hasColumn('batch_consumptions', 'user_id')) {
            Schema::table('batch_consumptions', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('revenue')->constrained('users')->nullOnDelete();
                $table->string('note', 255)->nullable()->after('user_id');
            });
        }

        if (! Schema::hasTable('rejected_outputs')) {
            Schema::create('rejected_outputs', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 20)->unique();                 // REJ-2026-0001
                $table->string('item_name', 150);                          // what was being made
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->unsignedInteger('quantity')->default(1);           // how many came out rejected
                $table->enum('cause', ['machine', 'process', 'other'])->default('machine');
                $table->string('reason', 255);
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->decimal('material_cost', 12, 2)->default(0);       // cost of the materials lost
                $table->enum('status', ['rejected', 'scrap_sold', 'discarded'])->default('rejected');
                $table->decimal('scrap_revenue', 12, 2)->nullable();       // only when sold as scrap
                $table->timestamp('scrap_sold_at')->nullable();
                $table->string('scrap_note', 255)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'created_at']);
            });
        }

        // The materials a rejected output used up are 'wasted' rows in batch_adjustments.
        if (! Schema::hasColumn('batch_adjustments', 'rejected_output_id')) {
            Schema::table('batch_adjustments', function (Blueprint $table) {
                $table->foreignId('rejected_output_id')->nullable()->after('material_offcut_id')
                    ->constrained('rejected_outputs')->nullOnDelete();
            });
        }
    }

    public function down(): void {
        if (Schema::hasColumn('batch_adjustments', 'rejected_output_id')) {
            Schema::table('batch_adjustments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('rejected_output_id');
            });
        }
        Schema::dropIfExists('rejected_outputs');

        if (Schema::hasColumn('batch_consumptions', 'user_id')) {
            Schema::table('batch_consumptions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
                $table->dropColumn('note');
            });
        }

        if (Schema::hasColumn('materials', 'inventory_type')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn('inventory_type');
            });
        }
    }
};
