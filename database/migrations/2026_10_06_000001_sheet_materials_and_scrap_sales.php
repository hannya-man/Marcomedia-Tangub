<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sheet materials and scrap sales.
//   Sheet materials - a continuous material cut by size from whole sheets, like sintra board.
//                     sheet_width x sheet_height is one sheet in feet (4 x 8). Stock is in sq ft
//                     and every sheet is its own batch (OCT-2026-SB3-1), so a job can take a
//                     whole sheet, or cut its size from the open sheet and leave the rest.
//   Scrap sales     - rejects that are still sold become a real sale (scrap_sale_id), so the money
//                     is in Sales and in the cashier's cash count. It still counts as zero profit.
//                     A scrap line has no product, so sale_items.product_id can be empty.
return new class extends Migration {
    public function up(): void {
        if (! Schema::hasColumn('materials', 'sheet_width')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->decimal('sheet_width', 8, 2)->nullable()->after('pack_size');
                $table->decimal('sheet_height', 8, 2)->nullable()->after('sheet_width');
            });
        }

        DB::statement('ALTER TABLE sale_items MODIFY product_id BIGINT UNSIGNED NULL');

        if (! Schema::hasColumn('rejected_outputs', 'scrap_sale_id')) {
            Schema::table('rejected_outputs', function (Blueprint $table) {
                $table->foreignId('scrap_sale_id')->nullable()->after('scrap_revenue')
                    ->constrained('sales')->nullOnDelete();
            });
        }
    }

    public function down(): void {
        // Checked first: MySQL can't undo half a rollback.
        $scrapLines = DB::table('sale_items')->whereNull('product_id')->count();
        if ($scrapLines > 0) {
            throw new RuntimeException("{$scrapLines} sale line(s) have no product (scrap sales). "
                . 'Remove them before rolling back, so product_id can be required again.');
        }

        if (Schema::hasColumn('rejected_outputs', 'scrap_sale_id')) {
            Schema::table('rejected_outputs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('scrap_sale_id');
            });
        }

        DB::statement('ALTER TABLE sale_items MODIFY product_id BIGINT UNSIGNED NOT NULL');

        if (Schema::hasColumn('materials', 'sheet_width')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn(['sheet_width', 'sheet_height']);
            });
        }
    }
};
