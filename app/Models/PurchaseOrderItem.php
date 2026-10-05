<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'material_id', 'packs_ordered', 'qty_per_pack', 'cost_per_pack'];

    protected $casts = [
        'packs_ordered' => 'integer',
        'qty_per_pack' => 'decimal:3',
        'cost_per_pack' => 'decimal:2',
    ];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function material() { return $this->belongsTo(Material::class); }

    // One batch per pack received. This is the PO -> batch link.
    public function batches() { return $this->hasMany(InventoryBatch::class); }

    public function getPacksReceivedAttribute(): int
    {
        return $this->batches()->count();
    }

    public function getPacksPendingAttribute(): int
    {
        return max($this->packs_ordered - $this->packs_received, 0);
    }
}
