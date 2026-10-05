<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    // active_guard is a generated column. Never write to it.
    protected $fillable = [
        'material_id', 'type', 'status', 'stock_level', 'threshold', 'message',
        'acknowledged_by', 'acknowledged_at', 'resolved_at',
    ];

    protected $casts = [
        'stock_level' => 'decimal:3',
        'threshold' => 'decimal:3',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function material() { return $this->belongsTo(Material::class); }
    public function acknowledger() { return $this->belongsTo(User::class, 'acknowledged_by'); }

    public function scopeActive($query) { return $query->where('status', 'active'); }

    // Most urgent first: out of stock, then low stock, then transfer reminders.
    public function scopeMostUrgentFirst($query)
    {
        return $query->orderByRaw("FIELD(type, 'out_of_stock', 'low_stock', 'transfer_needed')")->latest();
    }
}
