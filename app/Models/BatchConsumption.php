<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row = material taken from ONE batch for ONE job.
 * A job that used the last 3 sheets of SEPT-2026-CB-1 and 2 sheets of
 * OCT-2026-CB-1 has two rows. This is the traceability record.
 * A manual pull of a continuous material (fabric, ink) has no sale: user_id
 * says who pulled it and note says what for.
 */
class BatchConsumption extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inventory_batch_id', 'sale_id', 'sale_item_id', 'inventory_movement_id',
        'quantity_consumed', 'revenue', 'user_id', 'note', 'created_at',
    ];

    protected $casts = [
        'quantity_consumed' => 'decimal:3',
        'revenue' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function batch() { return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id'); }
    public function sale() { return $this->belongsTo(Sale::class); }
    public function saleItem() { return $this->belongsTo(SaleItem::class); }
    public function user() { return $this->belongsTo(User::class); }

    // Set when the material was used to produce finished stock (mugs, tumblers).
    public function movement() { return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id'); }
}
