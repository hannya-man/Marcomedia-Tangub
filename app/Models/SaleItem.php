<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'sale_id', 'product_id', 'product_variant_id', 'item_name', 'variant_name',
        'customization_details', 'width', 'height', 'quantity', 'unit_price', 'discount', 'subtotal',
    ];

    protected $casts = [
        'backordered_at' => 'datetime',
        'backorder_filled_at' => 'datetime',
    ];

    public function sale() { return $this->belongsTo(Sale::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }

    // Cut from a board or roll: the standard piece it was charged as (cut_blocks of them each).
    public function cutPiece() { return $this->belongsTo(Material::class, 'cut_material_id'); }

    // Sold while out of stock and still waiting for it (BackorderService).
    public function scopeOnOrder($query)
    {
        return $query->whereNotNull('backordered_at')->whereNull('backorder_filled_at');
    }

    public function isOnOrder(): bool
    {
        return $this->backordered_at !== null && $this->backorder_filled_at === null;
    }

    // "Charged as A3 (11.7 x 16.5 in)", or "5 ft of Tarpaulin Roll 4 ft". Null if not cut from one.
    public function cutNote(): ?string
    {
        $piece = $this->cutPiece;
        if (! $piece || ! $piece->cutFrom) {
            return null;
        }

        return $piece->cutFrom->isRoll()
            ? "{$this->cut_blocks} ft of {$piece->cutFrom->name}"
            : 'Charged as ' . ($this->cut_blocks > 1 ? "{$this->cut_blocks} x " : '') . $piece->cutLabel();
    }
}
