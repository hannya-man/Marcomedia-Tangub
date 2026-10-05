<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// Used Materials: one line every time material is opened, used, rejected or removed.
// sale_item_id is the sale line that used it (where an order sits), sale_id the invoice.
// pack_id is empty only for raw-material stock added or removed by hand.
class UsedMaterial extends Model
{
    // A log line is never edited, so the table only has created_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'pack_id', 'material_id', 'type', 'quantity', 'qty_before', 'qty_after',
        'sale_id', 'sale_item_id', 'reason', 'note', 'user_id',
    ];

    protected $casts = [
        'quantity' => 'float',
        'qty_before' => 'float',
        'qty_after' => 'float',
        'created_at' => 'datetime',
    ];

    public function pack() { return $this->belongsTo(MaterialPack::class, 'pack_id'); }
    public function material() { return $this->belongsTo(Material::class); }
    public function sale() { return $this->belongsTo(Sale::class); }
    public function saleItem() { return $this->belongsTo(SaleItem::class, 'sale_item_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'open' => 'Opened',
            'sale' => 'Sale',
            'job' => 'Used for a job',
            'error' => 'Error, rejected',
            'adjustment' => match ($this->reason) {
                'used' => 'Removed as used',
                'received' => 'Received',
                default => 'Adjustment',
            },
            'close' => $this->reason === 'used_up' ? 'Used up' : 'Pack closed',
            'void' => 'Sale voided',
            default => ucfirst((string) $this->type),
        };
    }
}
