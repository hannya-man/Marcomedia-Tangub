<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The four inventory types:
//   Products       - finished goods; already in products / product_variants.
//   Raw Materials  - materials.inventory_type = 'raw': whole units, like a roll of fabric.
//                    Usage can't be calculated, so a unit is removed by hand when used up.
//   Materials      - materials.inventory_type = 'material': pieces you can count.
//   Used Materials - the used_materials table below.
//
// Safe to run again after a failed run, and on a database that already has the
// tables from the earlier "packs" version: it converts those instead of
// creating them twice, so packs opened while testing are kept.
return new class extends Migration {
    public function up(): void {
        // Empty means "not set up yet": the material keeps its old running total
        // until someone puts it under Raw Materials or Materials.
        if (! Schema::hasColumn('materials', 'inventory_type')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->string('inventory_type', 10)->nullable()->after('unit');
            });
        }

        $this->upgradeFromPacksVersion();

        // A delivery (or month) of one material, e.g. "September 2026 CB".
        // It starts at zero: its quantity is whatever packs are opened from it.
        if (! Schema::hasTable('material_batches')) {
            Schema::create('material_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
                $table->string('label', 60);
                $table->string('supplier', 100)->nullable();
                $table->date('received_on')->nullable();
                $table->timestamps();
                $table->unique(['material_id', 'label']);
            });
        }

        // One pack of pieces, or one whole unit (a roll), in use, e.g. "September 2026 CB - 1".
        // Only one per material is current (status open or empty) at a time;
        // opening the next one closes it.
        if (! Schema::hasTable('material_packs')) {
            Schema::create('material_packs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
                $table->foreignId('batch_id')->constrained('material_batches')->cascadeOnDelete();
                $table->unsignedInteger('pack_no');
                $table->string('code', 80);
                $table->decimal('initial_qty', 10, 2);
                $table->decimal('remaining_qty', 10, 2);
                $table->enum('status', ['open', 'empty', 'closed'])->default('open');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('close_reason')->nullable();
                $table->timestamps();
                $table->unique(['material_id', 'code']);
                $table->index(['material_id', 'status']);
            });
        }

        // Used Materials: one line every time material is opened, used, rejected or removed.
        // sale_item_id is the sale line that used it (where an order sits), sale_id the invoice.
        // pack_id is empty only for raw-material stock added or removed by hand.
        if (! Schema::hasTable('used_materials')) {
            Schema::create('used_materials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pack_id')->nullable()->constrained('material_packs')->cascadeOnDelete();
                $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
                $table->enum('type', ['open', 'sale', 'job', 'error', 'adjustment', 'close', 'void']);
                $table->decimal('quantity', 10, 2);   // signed: + in, - out; 0 when a sale is only tied to a raw unit
                $table->decimal('qty_before', 10, 2);
                $table->decimal('qty_after', 10, 2);
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
                $table->string('reason', 30)->nullable();   // received, used, broken, miscount, other, leftover, used_up
                $table->string('note')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->index(['pack_id', 'created_at']);
                $table->index(['material_id', 'created_at']);
                $table->index(['type', 'created_at']);
            });
        }
    }

    public function down(): void {
        Schema::dropIfExists('used_materials');
        Schema::dropIfExists('material_packs');
        Schema::dropIfExists('material_batches');

        if (Schema::hasColumn('materials', 'inventory_type')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn('inventory_type');
            });
        }
    }

    // The earlier "packs" version logged to pack_movements and marked materials
    // with count_type ('whole' or 'piece'). material_batches and material_packs
    // are the same in both versions, so they are kept as they are.
    private function upgradeFromPacksVersion(): void
    {
        if (Schema::hasTable('pack_movements') && ! Schema::hasTable('used_materials')) {
            Schema::rename('pack_movements', 'used_materials');
            DB::statement("ALTER TABLE used_materials MODIFY type ENUM('open','sale','job','error','adjustment','close','void') NOT NULL");
            Schema::table('used_materials', function (Blueprint $table) {
                $table->index(['type', 'created_at']);
            });
        }

        if (Schema::hasColumn('materials', 'count_type')) {
            // 'whole' was only ever set on materials tracked as whole units.
            DB::table('materials')->whereNull('inventory_type')->where('count_type', 'whole')
                ->update(['inventory_type' => 'raw']);

            // 'piece' was the default for every material, so only the ones that
            // already have packs become Materials. The rest stay "not set up yet".
            if (Schema::hasTable('material_packs')) {
                DB::table('materials')->whereNull('inventory_type')->where('count_type', 'piece')
                    ->whereIn('id', DB::table('material_packs')->select('material_id'))
                    ->update(['inventory_type' => 'material']);
            }

            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn('count_type');
            });
        }
    }
};
