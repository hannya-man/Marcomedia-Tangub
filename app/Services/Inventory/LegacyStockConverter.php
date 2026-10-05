<?php
namespace App\Services\Inventory;

use App\Models\InventoryBatch;
use App\Models\Material;

/**
 * One-time go-live step. Before this change, materials.stock_quantity was typed in by hand
 * (and the Restock button added to it without a batch). This turns that number into batches.
 *
 *   stock > batches      -> opening batch for the difference (no PO)
 *   stock < 0            -> reset to what the batches hold (0 if none); count it
 *   stock < batches      -> batches win; count the open batch
 *
 * Run: php artisan inventory:convert-legacy-stock          (shows the plan)
 *      php artisan inventory:convert-legacy-stock --apply  (does it)
 */
class LegacyStockConverter
{
    public function __construct(private BatchInventoryService $inventory)
    {
    }

    public function plan(): array
    {
        $rows = [];
        foreach (Material::orderBy('name')->get() as $material) {
            $stock = round((float) $material->stock_quantity, 3);
            $batchTotal = round((float) InventoryBatch::where('material_id', $material->id)
                ->whereIn('status', ['unopened', 'open'])->sum('remaining_quantity'), 3);
            $difference = round($stock - $batchTotal, 3);

            if ($material->archived_at) {
                $action = 'skip (archived)';
            } elseif ($difference > 0.0005) {
                $action = 'add opening batch';
            } elseif ($stock < -0.0005) {
                $action = 'reset negative stock';
            } elseif ($difference < -0.0005) {
                $action = 'count needed';
            } else {
                $action = 'ok';
            }

            $rows[] = [
                'material_id' => $material->id,
                'material' => $material->name,
                'code' => $material->code ?: Material::suggestCode($material->name) . ' (new)',
                'stock_quantity' => $stock,
                'batch_total' => $batchTotal,
                'difference' => $difference,
                'action' => $action,
            ];
        }

        return $rows;
    }

    /** @return string[] what was done, one line per material that changed */
    public function apply(?int $userId = null, string $location = 'store'): array
    {
        $log = [];
        foreach ($this->plan() as $row) {
            if ($row['action'] === 'skip (archived)') {
                continue;
            }

            $material = Material::find($row['material_id']);
            $this->inventory->ensureCode($material);
            $f = function ($n) { return StockAlertService::formatQty($n); };

            if ($row['action'] === 'add opening batch') {
                $batch = $this->inventory->recordOpeningStock($material, $row['difference'], $location, $userId);
                $log[] = "{$material->name}: opening batch {$batch->batch_number} for {$f($row['difference'])} {$material->unit}.";
                continue;
            }

            $this->inventory->refreshMaterial($material);

            if ($row['action'] === 'reset negative stock') {
                $log[] = "{$material->name}: stock was {$f($row['stock_quantity'])} (below zero). Now {$f($row['batch_total'])}. Count it and record opening stock.";
            } elseif ($row['action'] === 'count needed') {
                $log[] = "{$material->name}: stock said {$f($row['stock_quantity'])} but batches hold {$f($row['batch_total'])}. Stock now follows the batches. Count the open batch.";
            }
        }

        return $log;
    }
}
