<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = ['po_number', 'supplier_id', 'status', 'ordered_at', 'expected_at', 'notes', 'created_by'];

    protected $casts = [
        'ordered_at' => 'date',
        'expected_at' => 'date',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    // Still waiting for some or all packs.
    public function scopeOpen($query) { return $query->whereIn('status', ['ordered', 'partial']); }

    // ordered -> partial -> received, based on how many packs have been checked in.
    public function refreshStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $items = $this->items()->withCount('batches')->get();
        $ordered = (int) $items->sum('packs_ordered');
        $received = (int) $items->sum('batches_count');

        $status = $received === 0 ? 'ordered' : ($received >= $ordered ? 'received' : 'partial');

        if ($status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }
}
