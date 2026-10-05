<?php
namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPack;
use App\Models\ProductVariant;

/**
 * What is low, empty or not in use right now, across Products, Raw Materials
 * and Materials. Used by the banner in the layout, so every user sees it on
 * every page without having to remember to check.
 */
class StockAlerts
{
    public function summary(): array
    {
        $materials = Material::whereNull('archived_at')->orderBy('name')->get();
        $ids = $materials->pluck('id')->all();

        $onPacks = MaterialPack::whereIn('material_id', $ids)->distinct()->pluck('material_id')->flip();
        $current = MaterialPack::whereIn('material_id', $ids)
            ->whereIn('status', ['open', 'empty'])
            ->get()
            ->keyBy('material_id');

        $items = collect();
        foreach ($materials as $m) {
            $item = $this->alertFor($m, $onPacks->has($m->id), $current->get($m->id));
            if ($item) {
                $items->push($item);
            }
        }

        // Same rule as the dashboard and Low Stock page: sellable stock (stock minus damaged).
        $products = ProductVariant::where('status', 'active')
            ->whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))
            ->count();

        return [
            'materials' => $items,
            'products' => $products,
            'total' => $items->count() + $products,
        ];
    }

    protected function alertFor(Material $m, bool $onPacks, ?MaterialPack $pack): ?array
    {
        $stock = (float) $m->stock_quantity;
        $low = $stock <= (float) $m->low_stock_threshold;

        if ($onPacks && PackService::isRaw($m)) {
            $parts = [];
            if (! $pack) {
                $parts[] = 'none in use';
            }
            if ($low) {
                $parts[] = PackService::fmt($stock) . " {$m->unit} left in stock";
            }

            return $parts ? $this->item($m, implode(', ', $parts), 'stock.raw') : null;
        }

        if ($onPacks) {
            if (! $pack) {
                return $this->item($m, 'no pack is open', 'stock.pieces');
            }
            if ($pack->status === 'empty') {
                return $this->item($m, "pack {$pack->code} is empty", 'stock.pieces');
            }

            return $low
                ? $this->item($m, PackService::fmt($pack->remaining_qty) . " {$m->unit} left in {$pack->code}", 'stock.pieces')
                : null;
        }

        if (! $low) {
            return null;
        }

        $route = match ($m->inventory_type) {
            'raw' => 'stock.raw',
            'material' => 'stock.pieces',
            default => 'inventory.materials',
        };

        return $this->item($m, $stock <= 0 ? 'out of stock' : PackService::fmt($stock) . " {$m->unit} left", $route);
    }

    protected function item(Material $m, string $text, string $route): array
    {
        return ['name' => $m->name, 'text' => "{$m->name}: {$text}", 'url' => route($route)];
    }
}
