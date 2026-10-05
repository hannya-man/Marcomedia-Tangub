<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'city', 'contact_person', 'phone', 'email', 'lead_time_days', 'notes', 'archived_at'];

    protected $casts = [
        'lead_time_days' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class); }

    // Materials that are normally ordered from this supplier.
    public function materials() { return $this->hasMany(Material::class, 'default_supplier_id'); }

    public function scopeActive($query) { return $query->whereNull('archived_at'); }
}
