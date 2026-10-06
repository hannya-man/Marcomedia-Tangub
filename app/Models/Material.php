<?php
namespace App\Models;

use App\Services\Inventory\StockAlertService;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    // stock_quantity is a cached total of all unclosed batches (store + warehouse).
    // BatchInventoryService keeps it in sync. Never edit it by hand.
    // low_stock_threshold is the reorder point.
    // sheet_width x sheet_height (feet) is set only for sheet materials, like sintra board:
    // stock is in sq ft, pack_size is the sq ft in one sheet, and each sheet is its own batch.
    protected $fillable = [
        'material_category_id', 'name', 'code', 'unit', 'inventory_type', 'pack_size', 'sheet_width', 'sheet_height',
        'stock_quantity', 'low_stock_threshold', 'reorder_packs', 'default_supplier_id', 'cost_per_unit', 'archived_at',
    ];

    protected $casts = [
        'stock_quantity' => 'decimal:3',
        'low_stock_threshold' => 'decimal:3',
        'pack_size' => 'decimal:3',
        'sheet_width' => 'decimal:2',
        'sheet_height' => 'decimal:2',
        'reorder_packs' => 'integer',
        'cost_per_unit' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    public function category() { return $this->belongsTo(MaterialCategory::class, 'material_category_id'); }

    public function variants()
    {
        return $this->belongsToMany(ProductVariant::class, 'variant_materials')
            ->withPivot('quantity_per_unit', 'consumption_type')
            ->withTimestamps();
    }

    public function batches() { return $this->hasMany(InventoryBatch::class); }

    // The one batch staff are cutting from right now (null if none).
    public function activeBatch() { return $this->hasOne(InventoryBatch::class)->where('status', 'open'); }

    public function offcuts() { return $this->hasMany(MaterialOffcut::class); }
    public function availableOffcuts() { return $this->hasMany(MaterialOffcut::class)->where('status', 'available'); }
    public function alerts() { return $this->hasMany(StockAlert::class); }
    public function activeAlerts() { return $this->hasMany(StockAlert::class)->where('status', 'active'); }
    public function defaultSupplier() { return $this->belongsTo(Supplier::class, 'default_supplier_id'); }
    public function purchaseOrderItems() { return $this->hasMany(PurchaseOrderItem::class); }

    // Codes are short and uppercase: "cb" -> "CB", "acr 3mm" -> "ACR3MM".
    public function setCodeAttribute($value): void
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
        $this->attributes['code'] = $clean === '' ? null : substr($clean, 0, 10);
    }

    /**
     * First letter of each word. Numbers are kept whole and the unit stuck
     * to a number is dropped, so sizes stay readable:
     *   "Central Board"            -> CB
     *   "Ceramic Mug Blank (11oz)" -> CMB11
     *   "Acrylic 3mm Clear"        -> A3C
     *   "Plywood"                  -> PLYW (one word: first 4 letters)
     * BatchInventoryService adds a number if the code is already taken (CB2).
     */
    public static function suggestCode(string $name): string
    {
        preg_match_all('/[A-Za-z]+|\d+/', $name, $matches, PREG_OFFSET_CAPTURE);
        $parts = $matches[0];

        if (count($parts) === 0) {
            return 'MAT';
        }
        if (count($parts) === 1 && !ctype_digit($parts[0][0])) {
            return strtoupper(substr($parts[0][0], 0, 4));
        }

        $code = '';
        $numberEndsAt = -1;
        foreach ($parts as [$part, $offset]) {
            if (ctype_digit($part)) {
                $code .= $part;
                $numberEndsAt = $offset + strlen($part);
            } elseif ($offset !== $numberEndsAt) {   // skip "oz" in "11oz"
                $code .= strtoupper($part[0]);
            }
        }

        return substr($code, 0, 8);
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity <= 0) return 'out_of_stock';
        if ($this->stock_quantity <= $this->low_stock_threshold) return 'low_stock';
        return 'in_stock';
    }

    // Cut by size from whole sheets, like sintra board.
    public function isSheet(): bool
    {
        return (float) $this->sheet_width > 0 && (float) $this->sheet_height > 0;
    }

    // Sq ft in one sheet: 4 x 8 ft -> 32.
    public function sheetArea(): float
    {
        return round((float) $this->sheet_width * (float) $this->sheet_height, 3);
    }

    // "4 x 8 ft"
    public function sheetLabel(): string
    {
        return StockAlertService::formatQty($this->sheet_width) . ' x ' . StockAlertService::formatQty($this->sheet_height) . ' ft';
    }
}
