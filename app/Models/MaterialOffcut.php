<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialOffcut extends Model
{
    protected $fillable = [
        'material_id', 'source_batch_id', 'label', 'width', 'height', 'quantity', 'status',
        'used_for_sale_item_id', 'created_by', 'used_at', 'discarded_at',
    ];

    protected $casts = [
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'quantity' => 'decimal:3',
        'used_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];

    public function material() { return $this->belongsTo(Material::class); }
    public function sourceBatch() { return $this->belongsTo(InventoryBatch::class, 'source_batch_id'); }
    public function saleItem() { return $this->belongsTo(SaleItem::class, 'used_for_sale_item_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeAvailable($query) { return $query->where('status', 'available'); }
}
