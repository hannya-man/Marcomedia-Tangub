<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $fillable = ['name', 'unit', 'stock_quantity', 'low_stock_threshold', 'cost_per_unit', 'archived_at'];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function variants()
    {
        return $this->belongsToMany(ProductVariant::class, 'variant_materials')
            ->withPivot('quantity_per_unit')
            ->withTimestamps();
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity <= 0) return 'out_of_stock';
        if ($this->stock_quantity <= $this->low_stock_threshold) return 'low_stock';
        return 'in_stock';
    }
}
