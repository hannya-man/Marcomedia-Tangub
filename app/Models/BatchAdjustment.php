<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wastage and correction log. quantity is signed: minus = removed, plus = added back.
 * Staff enter damaged / wasted / count_correction. The system writes
 * void_return, to_offcut and write_off on its own.
 */
class BatchAdjustment extends Model
{
    public $timestamps = false;

    public const STAFF_TYPES = ['damaged', 'wasted'];

    protected $fillable = [
        'inventory_batch_id', 'type', 'quantity', 'quantity_before', 'quantity_after', 'reason',
        'sale_item_id', 'source_batch_id', 'material_offcut_id', 'user_id', 'created_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'quantity_before' => 'decimal:3',
        'quantity_after' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function batch() { return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id'); }
    public function sourceBatch() { return $this->belongsTo(InventoryBatch::class, 'source_batch_id'); }
    public function saleItem() { return $this->belongsTo(SaleItem::class); }
    public function offcut() { return $this->belongsTo(MaterialOffcut::class, 'material_offcut_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
