<?php
namespace App\Services;

use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialPack;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\UsedMaterial;
use Illuminate\Support\Facades\DB;

/**
 * Every change to Raw Materials and Materials goes through this class, so the
 * pack, the material's stock_quantity and Used Materials always agree.
 *
 *  - Raw Materials (materials.inventory_type = 'raw'): whole units, like a roll
 *    of fabric. How much one job uses can't be calculated, so nothing is
 *    subtracted per job: a sale is tied to the unit in use, and the unit is
 *    removed by hand when it is used up.
 *    stock_quantity = whole units still in stock.
 *  - Materials (materials.inventory_type = 'material'): pieces you can count,
 *    e.g. a pack of 1,000. Sales and jobs take pieces out of the open pack;
 *    broken pieces are an adjustment.
 *    stock_quantity = what is left in the open pack.
 *  - Used Materials: every change is written to used_materials.
 *  - Products keep their own stock in product_variants and are not touched here.
 *
 * For both kinds of material:
 *  - at most one current pack or unit per material (status open or empty)
 *  - opening the next one closes the current one
 *  - an error (misprint, reject) is logged as used, never as money
 *
 * Problems are thrown as RuntimeException with a plain message, so a
 * controller can show it as is.
 */
class PackService
{
    /** 12.5 -> "12.5", 1000 -> "1,000" */
    public static function fmt($n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    }

    public static function isRaw(Material $material): bool
    {
        return ($material->inventory_type ?? null) === 'raw';
    }

    /** "October 2026 CB" for Central Board, opened this month. */
    public static function suggestLabel(string $materialName): string
    {
        $initials = collect(preg_split('/\s+/', trim($materialName)))
            ->filter(fn ($word) => preg_match('/^[\pL\pN]/u', $word))
            ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->take(3)
            ->implode('');

        return now()->format('F Y') . ($initials !== '' ? ' ' . $initials : '');
    }

    /** A material is tracked this way once its first pack or unit has been opened. */
    public function usesPacks(Material $material): bool
    {
        return MaterialPack::where('material_id', $material->id)->exists();
    }

    public function currentPack(Material $material): ?MaterialPack
    {
        return MaterialPack::where('material_id', $material->id)
            ->whereIn('status', ['open', 'empty'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Open the next pack or unit. The current one is closed first.
     *  Materials: the new pack holds $qty pieces.
     *  Raw Materials: one unit leaves stock (49 rolls become 48); $qty is ignored.
     */
    public function openPack(Material $material, string $batchLabel, float $qty, array $meta = []): MaterialPack
    {
        $label = trim($batchLabel);
        if ($label === '') {
            throw new \RuntimeException('Enter a batch label, for example ' . static::suggestLabel($material->name) . '.');
        }

        $userId = $meta['user_id'] ?? null;

        return DB::transaction(function () use ($material, $label, $qty, $meta, $userId) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->inventory_type, ['raw', 'material'], true)) {
                throw new \RuntimeException("Put {$locked->name} under Raw Materials or Materials first.");
            }

            $raw = static::isRaw($locked);

            if ($raw) {
                if ($this->cents($locked->stock_quantity) < 100) {
                    throw new \RuntimeException("No {$locked->name} left in stock. Add stock first.");
                }
                $qty = 1.0;
            } else {
                $qty = round($qty, 2);
                if ($qty <= 0) {
                    throw new \RuntimeException('Enter how many pieces are in the new pack.');
                }
            }

            $old = $this->lockedCurrentPack($locked->id);
            if ($old) {
                $this->closePack($locked, $old, 'Replaced by the next one', $userId);
            }

            $batch = MaterialBatch::firstOrCreate(
                ['material_id' => $locked->id, 'label' => $label],
                ['supplier' => $meta['supplier'] ?? null, 'received_on' => now()->toDateString()]
            );

            $packNo = (int) MaterialPack::where('batch_id', $batch->id)->max('pack_no') + 1;

            $pack = MaterialPack::create([
                'material_id' => $locked->id,
                'batch_id' => $batch->id,
                'pack_no' => $packNo,
                'code' => $batch->label . ' - ' . $packNo,
                'initial_qty' => $qty,
                'remaining_qty' => $qty,
                'status' => 'open',
                'opened_at' => now(),
                'opened_by' => $userId,
            ]);

            $this->log($pack, 'open', $qty, 0.0, $qty, ['user_id' => $userId, 'note' => $meta['note'] ?? null]);

            $this->setStock($locked->id, $raw
                ? ($this->cents($locked->stock_quantity) - 100) / 100
                : $qty);

            return $pack;
        });
    }

    /** Close the current pack, or mark the raw unit in use as used up, without opening another. */
    public function closeCurrent(Material $material, ?int $userId = null): MaterialPack
    {
        return DB::transaction(function () use ($material, $userId) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $raw = static::isRaw($locked);

            $pack = $this->lockedCurrentPack($locked->id);
            if (! $pack) {
                throw new \RuntimeException("{$locked->name} has nothing open to close.");
            }

            $this->closePack($locked, $pack, $raw ? 'Marked used up' : 'Closed by hand', $userId);

            if (! $raw) {
                $this->setStock($locked->id, 0);
            }

            return $pack->fresh();
        });
    }

    /**
     * Record that material was used, for a sale ('sale'), a job ('job') or an
     * error ('error': a misprint or reject, counted as used but never as money).
     *  Materials: $qty pieces come out of the open pack.
     *  Raw Materials: the use is tied to the unit in use; nothing is subtracted.
     */
    public function consume(Material $material, float $qty, string $type, array $meta = []): UsedMaterial
    {
        return DB::transaction(function () use ($material, $qty, $type, $meta) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $raw = static::isRaw($locked);

            $pack = $this->lockedCurrentPack($locked->id);
            if (! $pack) {
                throw new \RuntimeException($raw
                    ? "No {$locked->name} is in use. Start one on the Raw Materials page first."
                    : "{$locked->name} has no open pack. Open one on the Materials page first.");
            }

            if ($raw) {
                $left = (float) $pack->remaining_qty;

                return $this->log($pack, $type, 0.0, $left, $left, $meta);
            }

            $needC = $this->cents($qty);
            if ($needC <= 0) {
                throw new \RuntimeException('Enter how many were used.');
            }

            $beforeC = $this->cents($pack->remaining_qty);
            if ($pack->status === 'empty' || $beforeC <= 0) {
                throw new \RuntimeException("Pack {$pack->code} of {$locked->name} is empty. Open a new pack on the Materials page first.");
            }
            if ($needC > $beforeC) {
                throw new \RuntimeException(
                    "Pack {$pack->code} of {$locked->name} has only " . static::fmt($beforeC / 100) . " {$locked->unit} left, "
                    . 'but ' . static::fmt($needC / 100) . ' is needed. Open a new pack (what is left gets written off) or lower the quantity.'
                );
            }

            $afterC = $beforeC - $needC;
            $this->setRemaining($pack, $afterC / 100);
            $this->setStock($locked->id, $afterC / 100);

            return $this->log($pack, $type, -($needC / 100), $beforeC / 100, $afterC / 100, $meta);
        });
    }

    /**
     * Fix a count by hand.
     *  Materials: the open pack (broken pieces, a miscount, extra found).
     *  Raw Materials: the stock count (received from a supplier, a unit removed
     *  as used, a damaged roll, a miscount).
     */
    public function adjust(Material $material, float $signedQty, string $reason, ?string $note, ?int $userId): UsedMaterial
    {
        $deltaC = $this->cents($signedQty);
        if ($deltaC === 0) {
            throw new \RuntimeException('Enter how many to add or take out.');
        }

        return DB::transaction(function () use ($material, $deltaC, $reason, $note, $userId) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();

            if (static::isRaw($locked)) {
                $beforeC = $this->cents($locked->stock_quantity);
                $afterC = $beforeC + $deltaC;
                if ($afterC < 0) {
                    throw new \RuntimeException(
                        'Only ' . static::fmt($beforeC / 100) . " {$locked->unit} of {$locked->name} in stock, "
                        . "so you can't remove " . static::fmt(abs($deltaC) / 100) . '.'
                    );
                }

                $this->setStock($locked->id, $afterC / 100);

                return UsedMaterial::create([
                    'pack_id' => null,
                    'material_id' => $locked->id,
                    'type' => 'adjustment',
                    'quantity' => $deltaC / 100,
                    'qty_before' => $beforeC / 100,
                    'qty_after' => $afterC / 100,
                    'reason' => $reason,
                    'note' => $note,
                    'user_id' => $userId,
                ]);
            }

            $pack = $this->lockedCurrentPack($locked->id);
            if (! $pack) {
                throw new \RuntimeException("{$locked->name} has no open pack to adjust.");
            }

            $beforeC = $this->cents($pack->remaining_qty);
            $afterC = $beforeC + $deltaC;
            if ($afterC < 0) {
                throw new \RuntimeException(
                    "Pack {$pack->code} has only " . static::fmt($beforeC / 100) . " {$locked->unit} left, "
                    . "so you can't take out " . static::fmt(abs($deltaC) / 100) . '.'
                );
            }

            $this->setRemaining($pack, $afterC / 100);
            $this->setStock($locked->id, $afterC / 100);

            return $this->log($pack, 'adjustment', $deltaC / 100, $beforeC / 100, $afterC / 100, [
                'reason' => $reason,
                'note' => $note,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Called by the POS for each sale line. A made-to-order item (no finished
     * stock) uses its materials from the pack or unit in use. An item kept in
     * finished stock was made earlier, so nothing is taken here. Materials that
     * are not tracked this way yet keep their old running total.
     */
    public function consumeForSaleItem(SaleItem $item, ?ProductVariant $variant, bool $trackInventory, int $qty, ?int $userId = null): void
    {
        if ($trackInventory || ! $variant) {
            return;
        }

        foreach ($variant->materials as $material) {
            if (! $this->usesPacks($material)) {
                continue;
            }

            $need = round($qty * (float) $material->pivot->quantity_per_unit, 2);
            if (! static::isRaw($material) && $need <= 0) {
                continue;
            }

            $this->consume($material, $need, 'sale', [
                'sale_id' => $item->sale_id,
                'sale_item_id' => $item->id,
                'note' => $item->item_name . ' x' . $qty,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * A voided sale gives its pieces back to the pack they came from, as long as
     * that pack is still in use. A closed pack stays closed. A sale that was only
     * tied to a raw unit has nothing to give back.
     */
    public function reverseForSale(Sale $sale, ?int $userId = null): void
    {
        $groups = UsedMaterial::where('sale_id', $sale->id)
            ->whereIn('type', ['sale', 'void'])
            ->whereNotNull('pack_id')
            ->get()
            ->groupBy(fn ($use) => $use->sale_item_id . '-' . $use->pack_id);

        foreach ($groups as $uses) {
            $usedC = -$this->cents($uses->sum('quantity')); // sales are negative, earlier voids positive
            if ($usedC <= 0) {
                continue;
            }

            $first = $uses->first();

            DB::transaction(function () use ($first, $usedC, $sale, $userId) {
                Material::whereKey($first->material_id)->lockForUpdate()->first();

                $pack = MaterialPack::whereKey($first->pack_id)->lockForUpdate()->first();
                if (! $pack || $pack->status === 'closed') {
                    return;
                }

                $beforeC = $this->cents($pack->remaining_qty);
                $afterC = $beforeC + $usedC;
                $this->setRemaining($pack, $afterC / 100);
                $this->setStock($pack->material_id, $afterC / 100);

                $this->log($pack, 'void', $usedC / 100, $beforeC / 100, $afterC / 100, [
                    'sale_id' => $sale->id,
                    'sale_item_id' => $first->sale_item_id,
                    'user_id' => $userId,
                ]);
            });
        }
    }

    // ---------------------------------------------------------------

    protected function closePack(Material $material, MaterialPack $pack, string $reason, ?int $userId): void
    {
        $left = round((float) $pack->remaining_qty, 2);
        $raw = static::isRaw($material);

        if ($left > 0) {
            $this->log($pack, 'close', -$left, $left, 0.0, [
                'reason' => $raw ? 'used_up' : 'leftover',
                'note' => $raw ? $reason : 'Closed with ' . static::fmt($left) . ' left. ' . $reason,
                'user_id' => $userId,
            ]);
        }

        $pack->update([
            'remaining_qty' => 0,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $userId,
            'close_reason' => $reason,
        ]);
    }

    protected function setRemaining(MaterialPack $pack, float $remaining): void
    {
        $pack->remaining_qty = $remaining;
        $pack->status = $remaining <= 0 ? 'empty' : 'open';
        $pack->save();
    }

    protected function setStock(int $materialId, float $qty): void
    {
        Material::whereKey($materialId)->update(['stock_quantity' => $qty]);
    }

    protected function lockedCurrentPack(int $materialId): ?MaterialPack
    {
        return MaterialPack::where('material_id', $materialId)
            ->whereIn('status', ['open', 'empty'])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    protected function log(MaterialPack $pack, string $type, float $qty, float $before, float $after, array $extra = []): UsedMaterial
    {
        return UsedMaterial::create($extra + [
            'pack_id' => $pack->id,
            'material_id' => $pack->material_id,
            'type' => $type,
            'quantity' => $qty,
            'qty_before' => $before,
            'qty_after' => $after,
        ]);
    }

    // Work in whole hundredths so float rounding never decides "enough or not".
    protected function cents($n): int
    {
        return (int) round(((float) $n) * 100);
    }
}
