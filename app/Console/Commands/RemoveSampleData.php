<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the sample shop activity added on 13 Nov 2026 (sample cashiers, products, materials,
 * customers, sales, payments, shifts and their stock and activity records), listed row by row in
 * database/sample-data.json. Nothing else is touched. If a real record was made on a sample one since
 * (a real sale of a sample product, say), it says which and removes nothing.
 *
 *   php artisan sample-data:remove           what would be removed
 *   php artisan sample-data:remove --force   remove it
 */
class RemoveSampleData extends Command
{
    protected $signature = 'sample-data:remove {--force : Remove it (without this, only shows what would go)}';

    protected $description = 'Remove the sample data listed in database/sample-data.json';

    public function handle(): int
    {
        $file = database_path('sample-data.json');
        if (! is_file($file)) {
            $this->error('No database/sample-data.json: there is no sample data to remove.');

            return self::FAILURE;
        }
        $rows = json_decode(file_get_contents($file), true)['rows'] ?? [];

        $present = [];
        foreach ($rows as $table => $ids) {
            $present[$table] = collect($ids)->chunk(1000)->sum(fn ($chunk) => DB::table($table)->whereIn('id', $chunk->all())->count());
            $this->line(str_pad($table, 24) . $present[$table] . ' of ' . count($ids));
        }

        // Rows outside the list that point at a listed row: real records made on sample ones.
        $blocking = [];
        $keys = DB::select('SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
        foreach ($keys as $key) {
            if (empty($rows[$key->parent])) {
                continue;
            }
            $count = collect($rows[$key->parent])->chunk(1000)->sum(fn ($chunk) => DB::table($key->child)->whereIn($key->col, $chunk->all())
                ->when(! empty($rows[$key->child]), fn ($q) => $q->whereNotIn('id', $rows[$key->child]))->count());
            if ($count) {
                $blocking[] = "{$count} {$key->child} row(s) not in the sample point at sample {$key->parent} ({$key->child}.{$key->col})";
            }
        }
        if ($blocking) {
            $this->error("Removed nothing. Real records were made on sample ones since:\n" . implode("\n", $blocking));

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->info('Nothing removed yet. Run again with --force to remove these.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($rows as $table => $ids) {
                    foreach (array_chunk($ids, 1000) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->delete();
                    }
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        });
        $this->info('Sample data removed. You can now delete database/sample-data.json.');

        return self::SUCCESS;
    }
}
