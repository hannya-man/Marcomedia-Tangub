<?php
namespace App\Models;

use App\Services\Inventory\BatchInventoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * One pack, roll, bundle or box of a raw material, e.g. SEPT-2026-CB-1.
 *
 * Status:  unopened (sealed)  ->  open (being used)  ->  closed (empty or replaced)
 * Rule:    only ONE open batch per material. Enforced by BatchInventoryService
 *          and by the unique index one_open_batch_per_material.
 *
 * Do not change quantities or status directly on this model. Every change
 * goes through BatchInventoryService so it is logged and the totals stay right.
 */
class InventoryBatch extends Model
{
    // open_guard is a generated column. Never write to it.
    protected $fillable = [
        'material_id', 'purchase_order_item_id', 'batch_number', 'location',
        'opening_quantity', 'remaining_quantity', 'cost_per_unit', 'status',
        'received_at', 'received_by', 'opened_at', 'open_method', 'opened_by',
        'closed_at', 'close_reason', 'closed_by', 'transferred_at',
    ];

    protected $casts = [
        'opening_quantity' => 'decimal:3',
        'remaining_quantity' => 'decimal:3',
        'cost_per_unit' => 'decimal:2',
        'received_at' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'transferred_at' => 'datetime',
    ];

    public function material() { return $this->belongsTo(Material::class); }
    public function purchaseOrderItem() { return $this->belongsTo(PurchaseOrderItem::class); }
    public function consumptions() { return $this->hasMany(BatchConsumption::class); }
    public function adjustments() { return $this->hasMany(BatchAdjustment::class); }
    public function offcuts() { return $this->hasMany(MaterialOffcut::class, 'source_batch_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function opener() { return $this->belongsTo(User::class, 'opened_by'); }
    public function closer() { return $this->belongsTo(User::class, 'closed_by'); }

    // Scope names avoid open()/close() below.
    public function scopeUnopened($query) { return $query->where('status', 'unopened'); }
    public function scopeOpened($query) { return $query->where('status', 'open'); }
    public function scopeUsable($query) { return $query->whereIn('status', ['unopened', 'open']); }
    public function scopeInStore($query) { return $query->where('location', 'store'); }
    public function scopeInWarehouse($query) { return $query->where('location', 'warehouse'); }

    public function getTotalUsedAttribute(): float
    {
        return (float) $this->opening_quantity - (float) $this->remaining_quantity;
    }

    public function getTotalRevenueAttribute(): float
    {
        return (float) $this->consumptions()->sum('revenue');
    }

    // ------------------------------------------------------------------
    // Old entry points, kept so existing calls keep working. They now go
    // through the service, so they follow the one-open-batch rule.
    // ------------------------------------------------------------------

    /** @deprecated Deliveries go through a purchase order: BatchInventoryService::receive(). This records opening stock only. */
    public static function receive(Material $material, float $quantity, string $location, ?float $costPerUnit = null): self
    {
        return app(BatchInventoryService::class)->recordOpeningStock($material, $quantity, $location, Auth::id(), $costPerUnit);
    }

    /** @deprecated Use BatchInventoryService::openBatch(). Closes the current open batch first. */
    public function open(): void
    {
        app(BatchInventoryService::class)->openBatch($this, Auth::id());
        $this->refresh();
    }

    /** @deprecated Use BatchInventoryService::closeBatch(). */
    public function close(): void
    {
        app(BatchInventoryService::class)->closeBatch($this, Auth::id());
        $this->refresh();
    }

    /** @deprecated Use BatchInventoryService::consume(). */
    public static function deductStockFIFO(Material $material, float $qtyNeeded, float $revenue = 0, ?Sale $sale = null): void
    {
        app(BatchInventoryService::class)->consume($material, $qtyNeeded, [
            'sale_id' => $sale ? $sale->id : null,
            'revenue' => $revenue,
        ], Auth::id());
    }

    /**
     * Where every unit of this batch went. The five parts always add up:
     * opening - used_on_jobs - wasted - to_offcuts + returned + count_corrections = remaining
     */
    public function generateReport(): array
    {
        $usedOnJobs = (float) $this->consumptions()->sum('quantity_consumed');
        $byType = $this->adjustments()->selectRaw('type, SUM(quantity) AS total')->groupBy('type')->pluck('total', 'type');
        $sumOf = function (array $types) use ($byType) {
            return (float) collect($types)->sum(function ($type) use ($byType) {
                return (float) ($byType[$type] ?? 0);
            });
        };

        $wasted = -$sumOf(['damaged', 'wasted', 'write_off']);
        $toOffcuts = -$sumOf(['to_offcut']);
        $cost = $this->cost_per_unit !== null ? (float) $this->cost_per_unit : null;

        return [
            'batch_number' => $this->batch_number,
            'material' => $this->material->name,
            'unit' => $this->material->unit,
            'location' => $this->location,
            'status' => $this->status,
            'purchase_order' => optional(optional($this->purchaseOrderItem)->purchaseOrder)->po_number,
            'received_at' => optional($this->received_at)->toDateString(),
            'opened_at' => optional($this->opened_at)->toDateTimeString(),
            'closed_at' => optional($this->closed_at)->toDateTimeString(),
            'close_reason' => $this->close_reason,
            'opening_quantity' => (float) $this->opening_quantity,
            'used_on_jobs' => $usedOnJobs,
            'wasted' => $wasted,
            'to_offcuts' => $toOffcuts,
            'returned' => $sumOf(['void_return']),
            'count_corrections' => $sumOf(['count_correction']),
            'remaining' => (float) $this->remaining_quantity,
            'total_revenue' => $this->total_revenue,
            'cost_of_goods' => $cost !== null ? round($usedOnJobs * $cost, 2) : null,
            'cost_of_waste' => $cost !== null ? round($wasted * $cost, 2) : null,
        ];
    }
}
