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

    // Total SELLABLE stock across all variants — damaged units are
    // excluded, so this always matches what a customer can actually buy.
    public function getTotalStockAttribute(): int
    {
        return (int) $this->variants()->selectRaw('COALESCE(SUM(stock_quantity - damaged_quantity), 0) as total')->value('total');
    }

    public function getHasLowStockAttribute(): bool
    {
        return $this->variants()
            ->whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->exists();
    }
}
