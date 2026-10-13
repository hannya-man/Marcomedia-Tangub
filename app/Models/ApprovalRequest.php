<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something a cashier asked an owner to approve: adding a product, changing one, or voiding a sale.
 * Nothing changes until an owner approves it (ApprovalController). The product form is kept as the
 * cashier sent it (payload) and checked again when approved: the catalog may have changed since.
 */
class ApprovalRequest extends Model
{
    public const TYPES = [
        'product.create' => 'Add a product',
        'product.update' => 'Change a product',
        'sale.void' => 'Void a sale',
    ];

    protected $fillable = [
        'type', 'status', 'requested_by', 'product_id', 'sale_id', 'summary', 'payload', 'reason',
        'decided_by', 'decided_at', 'decision_note', 'requester_seen_at',
    ];

    protected $casts = [
        'requested_by' => 'integer',
        'decided_by' => 'integer',
        'product_id' => 'integer',
        'sale_id' => 'integer',
        'payload' => 'array',
        'decided_at' => 'datetime',
        'requester_seen_at' => 'datetime',
    ];

    public function requester() { return $this->belongsTo(User::class, 'requested_by')->withTrashed(); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by')->withTrashed(); }
    public function product() { return $this->belongsTo(Product::class); }
    public function sale() { return $this->belongsTo(Sale::class); }

    public function scopePending($query) { return $query->where('status', 'pending'); }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    // What the owner answered, closing the request. The cashier sees it on their Approvals page.
    public function decide(string $status, User $owner, ?string $note = null): void
    {
        $this->forceFill([
            'status' => $status,
            'decided_by' => $owner->id,
            'decided_at' => now(),
            'decision_note' => $note,
            'requester_seen_at' => null,
        ])->save();
    }
}
