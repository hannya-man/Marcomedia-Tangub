<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $fillable = ['material_category_id', 'name', 'unit', 'stock_quantity', 'low_stock_threshold', 'cost_per_unit', 'archived_at'];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function category() { return $this->belongsTo(MaterialCategory::class, 'material_category_id'); }

    public function variants()
    {
        return $this->belongsToMany(ProductVariant::class, 'variant_materials')
            ->withPivot('quantity_per_unit')
            ->withTimestamps();
    }

    public function batches() { return $this->hasMany(InventoryBatch::class); }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity <= 0) return 'out_of_stock';
        if ($this->stock_quantity <= $this->low_stock_threshold) return 'low_stock';
        return 'in_stock';
    }
}