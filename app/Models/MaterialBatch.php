<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// A delivery (or month) of one material, e.g. "September 2026 CB".
// Its quantity is whatever packs were opened from it, so it starts at zero.
class MaterialBatch extends Model
{
    protected $fillable = ['material_id', 'label', 'supplier', 'received_on'];

    protected $casts = [
        'received_on' => 'date',
    ];

    public function material() { return $this->belongsTo(Material::class); }
    public function packs() { return $this->hasMany(MaterialPack::class, 'batch_id'); }
}
