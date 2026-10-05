<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // The new rules can't be added on top of data that already breaks them.
        // Stop with a clear message instead of guessing which batch is right.
        $twoOpen = DB::table('inventory_batches')->where('status', 'open')
            ->groupBy('material_id')->havingRaw('COUNT(*) > 1')->pluck('material_id');
        if ($twoOpen->isNotEmpty()) {
            throw new RuntimeException('More than one open batch for material id(s): ' . $twoOpen->implode(', ')
                . '. Close the extra batches, then run the migration again.');
        }
        $closedWithStock = DB::table('inventory_batches')->where('status', 'closed')
            ->where('remaining_quantity', '>', 0)->pluck('batch_number');
        if ($closedWithStock->isNotEmpty()) {
            throw new RuntimeException('Closed batches still show stock: ' . $closedWithStock->implode(', ')
                . '. Set their remaining_quantity to 0 or reopen them, then run the migration again.');
        }

        Schema::table('inventory_batches', function (Blueprint $table) {
            // NULL only for opening stock encoded at go-live. Every delivery after that comes from a PO.
            $table->foreignId('purchase_order_item_id')->nullable()->after('material_id')
                ->constrained('purchase_order_items');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->enum('open_method', ['manual', 'auto'])->nullable()->after('opened_at');
            $table->foreignId('opened_by')->nullable()->after('open_method')->constrained('users')->nullOnDelete();
            $table->enum('close_reason', ['empty', 'replaced', 'manual'])->nullable()->after('closed_at');
            $table->foreignId('closed_by')->nullable()->after('close_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable()->after('closed_by');

            // 1 when the batch is open, NULL otherwise. The unique index below makes
            // MySQL itself refuse a second open batch of the same material
            // (NULLs never count as duplicates, so closed/unopened batches are free).
            $table->unsignedTinyInteger('open_guard')->nullable()
                ->storedAs("IF(`status` = 'open', 1, NULL)")->after('status');
            $table->unique(['material_id', 'open_guard'], 'one_open_batch_per_material');
        });

        // Stock can never go below zero, and a closed batch is always empty.
        // (Enforced on MySQL 8.0.16+ and MariaDB 10.2+; older MySQL ignores CHECK.)
        DB::statement('ALTER TABLE inventory_batches ADD CONSTRAINT chk_batch_not_negative CHECK (remaining_quantity >= 0)');
        DB::statement("ALTER TABLE inventory_batches ADD CONSTRAINT chk_closed_batch_is_empty CHECK (status <> 'closed' OR remaining_quantity = 0)");

        // Traceability: which customer line item (or which production run of
        // finished stock) took material from which batch.
        Schema::table('batch_consumptions', function (Blueprint $table) {
            $table->foreignId('sale_item_id')->nullable()->after('sale_id')->constrained('sale_items')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->after('sale_item_id')
                ->constrained('inventory_movements')->nullOnDelete();
        });

        // How one BOM line is measured:
        //   fixed      = quantity_per_unit x qty                 (1 mug blank per mug)
        //   per_area   = quantity_per_unit x width x height x qty (acrylic in sq in)
        //   per_length = quantity_per_unit x height x qty          (tarp roll in ft; height = length along the roll)
        Schema::table('variant_materials', function (Blueprint $table) {
            $table->enum('consumption_type', ['fixed', 'per_area', 'per_length'])->default('fixed')->after('quantity_per_unit');
        });

        // Job size for cut-to-order lines, entered in the material's unit of length.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('width', 8, 2)->nullable()->after('customization_details');
            $table->decimal('height', 8, 2)->nullable()->after('width');
        });
    }

    public function down(): void {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
        Schema::table('variant_materials', function (Blueprint $table) {
            $table->dropColumn('consumption_type');
        });
        Schema::table('batch_consumptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_movement_id');
            $table->dropConstrainedForeignId('sale_item_id');
        });

        foreach (['chk_closed_batch_is_empty', 'chk_batch_not_negative'] as $check) {
            try {
                DB::statement("ALTER TABLE inventory_batches DROP CONSTRAINT {$check}");
            } catch (\Throwable $e) {
                // Older MySQL never stored the CHECK, so there is nothing to drop.
            }
        }

        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropUnique('one_open_batch_per_material');
            $table->dropColumn('open_guard');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('opened_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('purchase_order_item_id');
            $table->dropColumn(['open_method', 'close_reason', 'transferred_at']);
        });
    }
};
