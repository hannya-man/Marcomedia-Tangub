<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id', 'name', 'sku', 'description', 'base_price',
        'has_variants', 'track_inventory', 'image_path', 'status', 'archived_at',
    ];

    protected $casts = [
        'has_variants' => 'boolean',
        'track_inventory' => 'boolean',
        'base_price' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    public function category() { return $this->belongsTo(Category::class); }
    public function variants() { return $this->hasMany(ProductVariant::class); }

    // Total stock across all variants (or just informational if not tracked)
    public function getTotalStockAttribute(): int
    {
        return $this->variants()->sum('stock_quantity');
    }

    public function getHasLowStockAttribute(): bool
    {
        return $this->variants()
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->exists();
    }
}
