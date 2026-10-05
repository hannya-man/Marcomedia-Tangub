<?php
namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialPack;
use App\Models\Sale;
use App\Models\UsedMaterial;
use App\Services\PackService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Three of the four inventory types:
 *   Raw Materials  - whole units like a roll of fabric (materials.inventory_type = raw)
 *   Materials      - pieces you can count (materials.inventory_type = material)
 *   Used Materials - everything opened, used, rejected or removed (used_materials)
 * The fourth, Products, is the existing Stock page (InventoryController).
 */
class StockController extends Controller
{
    public function __construct(private PackService $packs)
    {
    }

    public function raw()
    {
        return view('stock.raw', $this->board('raw'));
    }

    public function pieces()
    {
        return view('stock.materials', $this->board('material'));
    }

    public function used(Request $request)
    {
        $month = (string) $request->query('month', '');
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? Carbon::createFromFormat('!Y-m', $month)->startOfMonth()
            : now()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $types = [
            'all' => 'Everything',
            'sale' => 'Sales',
            'job' => 'Used for a job',
            'error' => 'Errors, rejected',
            'used_up' => 'Used up or removed as used',
            'adjustment' => 'Adjustments and stock added',
            'open' => 'Opened',
        ];
        $type = (string) $request->query('type', 'all');
        if (! array_key_exists($type, $types)) {
            $type = 'all';
        }
        $materialId = (int) $request->query('material', 0);

        // One row per material, type and reason for the month.
        $rows = UsedMaterial::whereBetween('created_at', [$start, $end])
            ->selectRaw('material_id, type, reason, SUM(quantity) as total, COUNT(*) as n')
            ->groupBy('material_id', 'type', 'reason')
            ->get();

        $materials = Material::whereIn('id', $rows->pluck('material_id')->unique())->orderBy('name')->get();

        $rawRows = [];
        $pieceRows = [];
        foreach ($materials as $m) {
            $group = $rows->where('material_id', $m->id);
            $sum = fn (string $t, ?string $r = null) => (float) $group
                ->filter(fn ($row) => $row->type === $t && ($r === null || $row->reason === $r))
                ->sum('total');
            $count = fn (string $t) => (int) $group->filter(fn ($row) => $row->type === $t)->sum('n');

            if (PackService::isRaw($m)) {
                $row = [
                    'material' => $m,
                    'used_up' => -1 * ($sum('close', 'used_up') + $sum('adjustment', 'used')),
                    'sales' => $count('sale'),
                    'jobs' => $count('job'),
                    'errors' => $count('error'),
                ];
                if ($row['used_up'] || $row['sales'] || $row['jobs'] || $row['errors']) {
                    $rawRows[] = $row;
                }
            } else {
                $errors = -1 * $sum('error');
                $row = [
                    'material' => $m,
                    'sales' => -1 * ($sum('sale') + $sum('void')),
                    'jobs' => -1 * $sum('job'),
                    'errors' => $errors,
                    'broken' => -1 * $sum('adjustment', 'broken'),
                    'lost' => $m->cost_per_unit !== null ? round($errors * (float) $m->cost_per_unit, 2) : null,
                ];
                if ($row['sales'] || $row['jobs'] || $row['errors'] || $row['broken']) {
                    $pieceRows[] = $row;
                }
            }
        }

        $totals = [
            'sales' => UsedMaterial::whereBetween('created_at', [$start, $end])
                ->where('type', 'sale')->whereNotNull('sale_id')->distinct()->count('sale_id'),
            'errors' => (int) $rows->where('type', 'error')->sum('n'),
            'lost' => collect($pieceRows)->sum(fn ($row) => $row['lost'] ?? 0),
            'used_up' => collect($rawRows)->sum('used_up'),
        ];

        $log = UsedMaterial::with(['pack', 'material', 'sale', 'user'])
            ->whereBetween('created_at', [$start, $end])
            ->when($materialId > 0, fn ($q) => $q->where('material_id', $materialId))
            ->when($type === 'used_up', fn ($q) => $q->where(function ($w) {
                $w->where(fn ($a) => $a->where('type', 'close')->where('reason', 'used_up'))
                    ->orWhere(fn ($a) => $a->where('type', 'adjustment')->where('reason', 'used'));
            }))
            ->when(! in_array($type, ['all', 'used_up'], true), fn ($q) => $q->where('type', $type))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $materialOptions = Material::whereNotNull('inventory_type')->orderBy('name')->get(['id', 'name']);

        return view('stock.used', compact(
            'start', 'type', 'types', 'materialId', 'materialOptions', 'rawRows', 'pieceRows', 'totals', 'log'
        ));
    }

    // Add a new raw material or material straight from its page.
    public function store(Request $request)
    {
        $data = $request->validate([
            'inventory_type' => 'required|in:raw,material',
            'name' => 'required|string|max:150',
            'unit' => 'required|string|max:20',
            'stock_quantity' => 'required|numeric|min:0',
            'low_stock_threshold' => 'nullable|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        // Leave blank fields to the table defaults.
        $material = new Material();
        $material->forceFill(array_filter($data, fn ($value) => $value !== null))->save();

        return back()->with('success', "{$material->name} added to " . $this->pageName($material) . '.');
    }

    // Put a material that has no type yet under Raw Materials or Materials.
    public function setType(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'inventory_type' => 'required|in:raw,material',
        ]);

        if ($this->packs->usesPacks($material)) {
            return back()->with('error', "{$material->name} already has packs, so it can't move to another type.");
        }

        $material->forceFill(['inventory_type' => $data['inventory_type']])->save();

        return back()->with('success', "{$material->name} is now under " . $this->pageName($material) . '.');
    }

    public function open(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'batch' => 'required|string|max:60',
            'quantity' => 'nullable|numeric|min:0.01',
            'supplier' => 'nullable|string|max:100',
        ]);

        try {
            $pack = $this->packs->openPack($material, $data['batch'], (float) ($data['quantity'] ?? 0), [
                'supplier' => $data['supplier'] ?? null,
                'user_id' => Auth::id(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (PackService::isRaw($material)) {
            $left = PackService::fmt($material->fresh()->stock_quantity);

            return back()->with('success', "{$pack->code} is now in use. {$left} {$material->unit} left in stock.");
        }

        return back()->with('success', "Pack {$pack->code} is open with " . PackService::fmt($pack->initial_qty) . " {$material->unit}.");
    }

    public function adjust(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'direction' => 'required|in:out,in',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'required|in:received,used,broken,miscount,other',
            'note' => 'nullable|string|max:255',
        ]);

        $signed = (float) $data['quantity'] * ($data['direction'] === 'out' ? -1 : 1);

        try {
            $use = $this->packs->adjust($material, $signed, $data['reason'], $data['note'] ?? null, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (PackService::isRaw($material)) {
            return back()->with('success', 'Saved. ' . PackService::fmt($use->qty_after) . " {$material->unit} of {$material->name} in stock.");
        }

        return back()->with('success', $this->leftMessage('Adjusted.', $use, $material));
    }

    public function usage(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'use_type' => 'required|in:job,error',
            'quantity' => 'nullable|numeric|min:0.01',
            'invoice' => 'nullable|string|max:30',
            'note' => 'nullable|string|max:255',
        ]);

        $sale = null;
        if (filled($data['invoice'] ?? null)) {
            $sale = Sale::where('invoice_number', trim($data['invoice']))->first();
            if (! $sale) {
                return back()->with('error', "There is no invoice {$data['invoice']}. Check the number, or leave it blank.");
            }
        }

        try {
            $use = $this->packs->consume($material, (float) ($data['quantity'] ?? 0), $data['use_type'], [
                'sale_id' => $sale?->id,
                'note' => $data['note'] ?? null,
                'user_id' => Auth::id(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $isError = $data['use_type'] === 'error';

        if (PackService::isRaw($material)) {
            return back()->with('success', $isError
                ? "Error logged against {$use->pack->code} and rejected. It counts as used, not as a sale amount."
                : "Use logged against {$use->pack->code}.");
        }

        $lead = $isError
            ? 'Error logged and rejected: ' . PackService::fmt($data['quantity'] ?? 0) . " {$material->unit}, counted as used, not as a sale amount."
            : 'Logged ' . PackService::fmt($data['quantity'] ?? 0) . " {$material->unit}.";

        return back()->with('success', $this->leftMessage($lead, $use, $material));
    }

    public function close(Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        try {
            $pack = $this->packs->closeCurrent($material, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', PackService::isRaw($material)
            ? "{$pack->code} is used up and moved to Used Materials. Start the next one before the next sale that needs {$material->name}."
            : "Pack {$pack->code} is closed. Open a new pack before the next sale or use of {$material->name}.");
    }

    // The cards for the Raw Materials or Materials page.
    private function board(string $type): array
    {
        $raw = $type === 'raw';
        $materials = Material::whereNull('archived_at')->where('inventory_type', $type)->orderBy('name')->get();
        $unset = Material::whereNull('archived_at')->whereNull('inventory_type')->orderBy('name')->get();
        $ids = $materials->pluck('id');

        $current = MaterialPack::whereIn('material_id', $ids)
            ->whereIn('status', ['open', 'empty'])
            ->get()
            ->keyBy('material_id');

        $packCounts = MaterialPack::whereIn('material_id', $ids)
            ->selectRaw('material_id, COUNT(*) as total')
            ->groupBy('material_id')
            ->pluck('total', 'material_id');

        // The six latest packs or units per material, with what happened to each.
        $history = MaterialPack::whereIn('material_id', $ids)
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->groupBy('material_id')
            ->map(fn ($packs) => $packs->take(6));

        $stats = UsedMaterial::whereIn('pack_id', $history->flatten()->pluck('id'))
            ->selectRaw('pack_id, type, SUM(quantity) as total, COUNT(*) as n')
            ->groupBy('pack_id', 'type')
            ->get()
            ->groupBy('pack_id')
            ->map(function ($rows) {
                $sum = $rows->pluck('total', 'type');
                $count = $rows->pluck('n', 'type');

                return [
                    'used' => -1 * ((float) ($sum['sale'] ?? 0) + (float) ($sum['job'] ?? 0) + (float) ($sum['void'] ?? 0)),
                    'errors' => -1 * (float) ($sum['error'] ?? 0),
                    'adjusted' => -1 * (float) ($sum['adjustment'] ?? 0),
                    'leftover' => -1 * (float) ($sum['close'] ?? 0),
                    'sales' => (int) ($count['sale'] ?? 0),
                    'jobs' => (int) ($count['job'] ?? 0),
                    'error_count' => (int) ($count['error'] ?? 0),
                ];
            });

        $batches = MaterialBatch::whereIn('material_id', $ids)->orderByDesc('id')->get()->groupBy('material_id');

        $order = ['empty' => 0, 'none' => 1, 'low' => 2, 'ok' => 3, 'new' => 4];

        $cards = $materials->map(function ($m) use ($raw, $current, $packCounts, $history, $batches) {
            $cur = $current->get($m->id);
            $low = (float) $m->stock_quantity <= (float) $m->low_stock_threshold;

            if ((int) ($packCounts[$m->id] ?? 0) === 0) {
                $state = 'new';
            } elseif (! $cur) {
                $state = 'none';
            } elseif (! $raw && $cur->status === 'empty') {
                $state = 'empty';
            } elseif ($low) {
                $state = 'low';
            } else {
                $state = 'ok';
            }

            $pct = (! $raw && $cur && $cur->initial_qty > 0)
                ? (int) max(0, min(100, round($cur->remaining_qty / $cur->initial_qty * 100)))
                : 0;

            return [
                'material' => $m,
                'current' => $cur,
                'state' => $state,
                'pct' => $pct,
                'history' => $history->get($m->id, collect()),
                'batches' => $batches->get($m->id, collect()),
            ];
        })->sortBy(fn ($c) => [$order[$c['state']], strtolower($c['material']->name)])->values();

        // What the dialogs need to know about each material.
        $payloads = $cards->mapWithKeys(function ($c) use ($type) {
            $m = $c['material'];
            $cur = $c['current'];

            return [$m->id => [
                'id' => $m->id,
                'name' => $m->name,
                'unit' => $m->unit,
                'kind' => $type,
                'new' => $c['state'] === 'new',
                'stock' => (float) $m->stock_quantity,
                'suggest' => PackService::suggestLabel($m->name),
                'cur_code' => $cur?->code,
                'cur_left' => $cur ? (float) $cur->remaining_qty : 0,
                'urls' => [
                    'open' => route('stock.open', $m),
                    'adjust' => route('stock.adjust', $m),
                    'usage' => route('stock.usage', $m),
                    'close' => route('stock.close', $m),
                ],
            ]];
        })->all();

        return compact('cards', 'payloads', 'stats', 'unset');
    }

    private function pageName(Material $material): string
    {
        return PackService::isRaw($material) ? 'Raw Materials' : 'Materials';
    }

    private function archived(Material $material)
    {
        return $material->archived_at
            ? back()->with('error', "{$material->name} is archived. Restore it on the Material list first.")
            : null;
    }

    private function leftMessage(string $lead, UsedMaterial $use, Material $material): string
    {
        $code = $use->pack->code;

        return $use->qty_after <= 0
            ? "{$lead} Pack {$code} is now empty. Open a new pack."
            : "{$lead} " . PackService::fmt($use->qty_after) . " {$material->unit} left in {$code}.";
    }
}
