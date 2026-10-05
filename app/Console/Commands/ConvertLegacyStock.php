<?php
namespace App\Console\Commands;

use App\Services\Inventory\LegacyStockConverter;
use Illuminate\Console\Command;

class ConvertLegacyStock extends Command
{
    protected $signature = 'inventory:convert-legacy-stock {--apply : Make the changes. Without this it only shows the plan.}';

    protected $description = 'One-time go-live step: turn typed-in material stock into opening batches.';

    public function handle(LegacyStockConverter $converter): int
    {
        $plan = $converter->plan();
        $this->table(['Material', 'Code', 'Stock', 'In batches', 'Action'], array_map(function ($row) {
            return [$row['material'], $row['code'], $row['stock_quantity'], $row['batch_total'], $row['action']];
        }, $plan));

        if (!$this->option('apply')) {
            $this->info('Dry run. Nothing changed. Run again with --apply to make these changes.');
            return self::SUCCESS;
        }

        foreach ($converter->apply() as $line) {
            $this->line($line);
        }
        $this->info('Done.');

        return self::SUCCESS;
    }
}
