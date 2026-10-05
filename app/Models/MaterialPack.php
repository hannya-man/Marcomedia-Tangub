<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// One pack of pieces (Materials) or one whole unit, like a roll (Raw Materials),
// e.g. "September 2026 CB - 1".
// status: open (in use), empty (ran out, waiting to be replaced), closed (done).
class MaterialPack extends Model
{
    protected $fillable = [
        'material_id', 'batch_id', 'pack_no', 'code', 'initial_qty', 'remaining_qty',
        'status', 'opened_at', 'closed_at', 'opened_by', 'closed_by', 'close_reason',
    ];

    protected $casts = [
        'initial_qty' => 'float',
        'remaining_qty' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function material() { return $this->belongsTo(Material::class); }
    public function batch() { return $this->belongsTo(MaterialBatch::class, 'batch_id'); }
    public function uses() { return $this->hasMany(UsedMaterial::class, 'pack_id'); }
    public function openedBy() { return $this->belongsTo(User::class, 'opened_by'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }

    // The pack in use right now, or the one that ran out and still has to be replaced.
    public function getIsCurrentAttribute(): bool
    {
        return in_array($this->status, ['open', 'empty'], true);
    }
}
