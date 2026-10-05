<?php
namespace App\Services\Inventory;

use App\Models\BatchConsumption;
use App\Models\InventoryBatch;
use App\Models\Material;
use App\Models\MaterialOffcut;
use App\Models\PurchaseOrderItem;
use App\Models\StockAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Replaces the logbook check.
 *
 * evaluate() runs inside every stock change (job, delivery, loss, count, void):
 *   total stock <= 0                         -> out_of_stock
 *   0 < total stock <= reorder point         -> low_stock
 *   no sealed pack left in the store, but
 *   the warehouse has some                   -> transfer_needed
 * One active alert per material and type. It is updated in place while the problem lasts
 * and resolves itself when stock is back. inventory:check-stock runs daily as a safety net.
 */
class StockAlertService
{
    private const EPSILON = 0.0005;

    public const LABELS = [
        'out_of_stock' => 'Out of stock',
        'low_stock' => 'Low stock',
        'transfer_needed' => 'Move stock from warehouse',
    ];

    public function evaluate(Material $material): void
    {
        $s = $this->summary($material);
        $live = $material->archived_at === null;

        $checks = [
            'out_of_stock' => $s['total'] <= self::EPSILON,
            'low_stock' => $s['total'] > self::EPSILON && $s['total'] <= $s['reorder_point'] + self::EPSILON,
            'transfer_needed' => $s['sealed_store_packs'] === 0 && $s['sealed_warehouse_packs'] > 0,
        ];

        foreach ($checks as $type => $on) {
            $this->toggle($material, $type, $live && $on, $s);
        }
    }

    /** Everything a person needs to decide: how much, where, what's on order, what offcuts exist. */
    public function summary(Material $material): array
    {
        $batches = InventoryBatch::where('material_id', $material->id)->whereIn('status', ['unopened', 'open'])->get();
        $open = $batches->firstWhere('status', 'open');
        $sealedStore = $batches->where('status', 'unopened')->where('location', 'store');
        $sealedWarehouse = $batches->where('status', 'unopened')->where('location', 'warehouse');

        $pendingPacks = 0;
        $pendingPos = [];
        $items = PurchaseOrderItem::with('purchaseOrder')->withCount('batches')
            ->where('material_id', $material->id)
            ->whereHas('purchaseOrder', function ($q) { $q->whereIn('status', ['ordered', 'partial']); })
            ->get();
        foreach ($items as $item) {
            $due = max($item->packs_ordered - $item->batches_count, 0);
            if ($due > 0) {
                $pendingPacks += $due;
                $pendingPos[] = $item->purchaseOrder->po_number;
            }
        }

        $offcuts = MaterialOffcut::where('material_id', $material->id)->where('status', 'available')->get();

        return [
            'total' => round((float) $batches->sum('remaining_quantity'), 3),
            'reorder_point' => round((float) $material->low_stock_threshold, 3),
            'active_batch' => $open ? $open->batch_number : null,
            'active_remaining' => $open ? round((float) $open->remaining_quantity, 3) : 0.0,
            'store_available' => round(($open ? (float) $open->remaining_quantity : 0) + (float) $sealedStore->sum('remaining_quantity'), 3),
            'sealed_store_packs' => $sealedStore->count(),
            'sealed_warehouse_packs' => $sealedWarehouse->count(),
            'warehouse_quantity' => round((float) $sealedWarehouse->sum('remaining_quantity'), 3),
            'pending_po_packs' => $pendingPacks,
            'pending_po_numbers' => array_values(array_unique($pendingPos)),
            'offcuts_available' => $offcuts->count(),
            'offcut_quantity' => round((float) $offcuts->sum('quantity'), 3),
        ];
    }

    public function acknowledge(StockAlert $alert, ?int $userId): StockAlert
    {
        if ($alert->status === 'active' && !$alert->acknowledged_at) {
            $alert->update(['acknowledged_by' => $userId, 'acknowledged_at' => now()]);
        }

        return $alert;
    }

    /**
     * Daily safety net: re-sync every material's total from its batches and re-check it.
     * Catches reorder points edited in the database, old code paths, and archived materials.
     */
    public function sweep(): Collection
    {
        $ids = Material::whereNull('archived_at')
            ->orWhereHas('alerts', function ($q) { $q->where('status', 'active'); })
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $material = Material::whereKey($id)->lockForUpdate()->first();
                $total = round((float) InventoryBatch::where('material_id', $id)->whereIn('status', ['unopened', 'open'])->sum('remaining_quantity'), 3);
                if (abs((float) $material->stock_quantity - $total) > self::EPSILON) {
                    $material->stock_quantity = $total;
                    $material->save();
                }
                $this->evaluate($material);
            });
        }

        return $this->activeAlerts();
    }

    public function activeAlerts(): Collection
    {
        return StockAlert::with(['material', 'acknowledger'])->active()->mostUrgentFirst()->get();
    }

    /** One email listing every active alert. Returns false when nothing was sent. */
    public function sendDigest(): bool
    {
        $to = config('inventory.alert_email');
        $alerts = $this->activeAlerts();
        if (!$to || $alerts->isEmpty()) {
            return false;
        }

        $lines = $alerts->map(function ($alert) {
            return '- [' . self::LABELS[$alert->type] . '] ' . $alert->message;
        })->implode("\n");

        Mail::raw('Stock alerts as of ' . now()->format('M j, Y g:i A') . ":\n\n{$lines}", function ($mail) use ($to, $alerts) {
            $mail->to($to)->subject("Daily stock check: {$alerts->count()} alert(s)");
        });

        return true;
    }

    /**
     * Suggested reorder point = average daily use x (supplier lead time + safety days), rounded up.
     * Null when there is no usage in the window yet.
     */
    public function suggestReorderPoint(Material $material): ?float
    {
        $days = max((int) config('inventory.usage_window_days', 30), 1);
        $used = (float) BatchConsumption::whereHas('batch', function ($q) use ($material) {
            $q->where('material_id', $material->id);
        })->where('created_at', '>=', now()->subDays($days))->sum('quantity_consumed');

        if ($used <= 0) {
            return null;
        }

        $lead = $material->defaultSupplier?->lead_time_days ?? 3;

        return (float) ceil($used / $days * ($lead + (int) config('inventory.safety_days', 3)));
    }

    /** 45.000 -> "45", 12.500 -> "12.5", 1234 -> "1,234". */
    public static function formatQty($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    }

    private function toggle(Material $material, string $type, bool $on, array $s): void
    {
        $alert = StockAlert::where('material_id', $material->id)->where('type', $type)
            ->where('status', 'active')->lockForUpdate()->first();

        if (!$on) {
            if ($alert) {
                $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
            return;
        }

        $data = [
            'stock_level' => $s['total'],
            'threshold' => $s['reorder_point'],
            'message' => Str::limit($this->message($type, $material, $s), 250),
        ];

        if ($alert) {
            $alert->update($data);   // same problem, fresher numbers, no new email
            return;
        }

        $alert = StockAlert::create($data + ['material_id' => $material->id, 'type' => $type, 'status' => 'active']);
        $this->notifyAfterCommit($alert, $material);
    }

    private function message(string $type, Material $m, array $s): string
    {
        $unit = $m->unit;

        if ($type === 'transfer_needed') {
            $where = $s['active_batch']
                ? "The store is on its last pack of {$m->name} ({$s['active_batch']}, " . self::formatQty($s['active_remaining']) . " {$unit} left)."
                : "No {$m->name} left in the store.";

            return "{$where} {$s['sealed_warehouse_packs']} sealed pack(s) in the warehouse. Move one to the store.";
        }

        $text = $type === 'out_of_stock'
            ? "{$m->name} is out of stock. Jobs that use it can't be sold."
            : "{$m->name}: " . self::formatQty($s['total']) . " {$unit} left, reorder point is " . self::formatQty($s['reorder_point']) . '.';

        if ($s['pending_po_packs'] > 0) {
            $text .= " {$s['pending_po_packs']} pack(s) already on order (" . implode(', ', $s['pending_po_numbers']) . '). Follow up before ordering more.';
        } else {
            $supplier = $m->defaultSupplier;
            $text .= ' Order ' . ($m->reorder_packs ? "{$m->reorder_packs} pack(s)" : 'more')
                . ($supplier ? " from {$supplier->name}" . ($supplier->city ? " ({$supplier->city})" : '') : '') . '.';
        }

        if ($s['offcuts_available'] > 0) {
            $text .= " {$s['offcuts_available']} offcut(s) on hand (" . self::formatQty($s['offcut_quantity']) . " {$unit}) for small jobs.";
        }

        return $text;
    }

    private function notifyAfterCommit(StockAlert $alert, Material $material): void
    {
        $to = config('inventory.alert_email');
        if (!$to) {
            return;
        }

        $subject = 'Stock alert: ' . self::LABELS[$alert->type] . " - {$material->name}";
        $body = $alert->message . "\n\nSee all stock alerts on the dashboard.";

        // Sent only after the sale or delivery is saved. If it rolls back, no email goes out.
        DB::afterCommit(function () use ($to, $subject, $body) {
            try {
                Mail::raw($body, function ($mail) use ($to, $subject) {
                    $mail->to($to)->subject($subject);
                });
            } catch (\Throwable $e) {
                Log::warning('Stock alert email failed: ' . $e->getMessage());
            }
        });
    }
}
