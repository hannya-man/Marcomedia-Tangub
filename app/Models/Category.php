<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'archived_at'];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function products() { return $this->hasMany(Product::class); }

    // The shop's order (the flyer's sections). Categories added later have no place, so they follow, by name.
    public function scopeInShopOrder($query)
    {
        return $query->orderByRaw('COALESCE(sort_order, 65535)')->orderBy('name');
    }
}
