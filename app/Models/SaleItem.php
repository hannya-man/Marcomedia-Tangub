<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'sale_id', 'product_id', 'product_variant_id', 'item_name', 'variant_name',
        'customization_details', 'quantity', 'unit_price', 'discount', 'subtotal',
    ];

    public function sale() { return $this->belongsTo(Sale::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
}
