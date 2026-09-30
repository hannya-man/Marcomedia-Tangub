<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchConsumption extends Model
{
    public $timestamps = false;
    protected $fillable = ['inventory_batch_id', 'sale_id', 'quantity_consumed', 'revenue', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function batch() { return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id'); }
    public function sale() { return $this->belongsTo(Sale::class); }
}
