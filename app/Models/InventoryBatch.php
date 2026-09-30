<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryBatch extends Model
{
    protected $fillable = [
        'material_id', 'batch_number', 'location', 'opening_quantity', 'remaining_quantity',
        'cost_per_unit', 'status', 'received_at', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'received_at' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function material() { return $this->belongsTo(Material::class); }
    public function consumptions() { return $this->hasMany(BatchConsumption::class); }

    public function getTotalUsedAttribute(): float
    {
        return $this->opening_quantity - $this->remaining_quantity;
    }

    public function getTotalRevenueAttribute(): float
    {
        return $this->consumptions()->sum('revenue');
    }

    // A new roll/sheet/bottle arrives. Doesn't touch stock until opened.
    public static function receive(Material $material, float $quantity, string $location, ?float $costPerUnit = null): self
    {
        return static::create([
            'material_id' => $material->id,
            'location' => $location,
            'batch_number' => 'BATCH-' . str_pad((static::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT),
            'opening_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'cost_per_unit' => $costPerUnit ?? $material->cost_per_unit,
            'status' => 'unopened',
            'received_at' => now(),
        ]);
    }

    // Staff clicks this when they physically start cutting from this batch.
    public function open(): void
    {
        $this->update(['status' => 'open', 'opened_at' => now()]);
    }

    // FIFO, store location only. Warehouse stock can never be sold
    // directly — it has to be received into the store as its own batch first.
    public static function deductStockFIFO(Material $material, float $qtyNeeded, float $revenue = 0, ?Sale $sale = null): void
    {
        DB::transaction(function () use ($material, $qtyNeeded, $revenue, $sale) {
            $remaining = $qtyNeeded;

            while ($remaining > 0) {
                $batch = static::where('material_id', $material->id)
                    ->where('location', 'store')
                    ->where('status', 'open')
                    ->orderBy('opened_at')
                    ->lockForUpdate()
                    ->first();

                if (!$batch) {
                    $batch = static::where('material_id', $material->id)
                        ->where('location', 'store')
                        ->where('status', 'unopened')
                        ->orderBy('received_at')
                        ->lockForUpdate()
                        ->first();

                    if (!$batch) {
                        throw new \RuntimeException("No store stock left for {$material->name}. Receive or transfer a new batch first.");
                    }
                    $batch->open();
                }

                $take = min($remaining, $batch->remaining_quantity);
                $revenueShare = $qtyNeeded > 0 ? round(($take / $qtyNeeded) * $revenue, 2) : 0;

                $batch->consumptions()->create([
                    'sale_id' => $sale?->id,
                    'quantity_consumed' => $take,
                    'revenue' => $revenueShare,
                    'created_at' => now(),
                ]);

                $batch->decrement('remaining_quantity', $take);
                $remaining -= $take;

                if ($batch->fresh()->remaining_quantity <= 0) {
                    $batch->close();
                }
            }
        });
    }

    public function close(): void
    {
        $this->update(['status' => 'closed', 'closed_at' => now()]);
    }

    public function generateReport(): array
    {
        return [
            'batch_number' => $this->batch_number,
            'material' => $this->material->name,
            'location' => $this->location,
            'total_used' => $this->total_used,
            'total_revenue' => $this->total_revenue,
            'cost_of_goods' => $this->cost_per_unit ? $this->total_used * $this->cost_per_unit : null,
        ];
    }
}
