<?php
namespace App\Console\Commands;

use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\StockAlertService;
use Illuminate\Console\Command;

/**
 * Daily safety net (scheduled in app/Console/Kernel.php). Alerts already update on every
 * sale and delivery; this catches anything changed outside the app and emails a summary.
 */
class CheckStockLevels extends Command
{
    protected $signature = 'inventory:check-stock {--email : Also email a summary of all active alerts}';

    protected $description = 'Check every material against its reorder point and verify that all batches balance.';

    public function handle(StockAlertService $alerts, BatchInventoryService $inventory): int
    {
        // Audit first, before the sweep re-syncs the totals, so mismatches are reported.
        $problems = $inventory->auditBatches();
        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        $active = $alerts->sweep();
        if ($active->isEmpty()) {
            $this->info('All materials are above their reorder points.');
        } else {
            $this->table(['Alert', 'Message', 'Since'], $active->map(function ($alert) {
                return [StockAlertService::LABELS[$alert->type], $alert->message, $alert->created_at->diffForHumans()];
            })->all());
        }

        if ($this->option('email')) {
            $this->line($alerts->sendDigest() ? 'Summary emailed.' : 'No email sent (no active alerts, or STOCK_ALERT_EMAIL is not set).');
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
