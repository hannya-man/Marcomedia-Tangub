<?php
namespace App\Services\Sales;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Xlsx;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sales from a sheet (sales written on paper while offline, say): one row per item sold, in the
 * columns of the report's "Items sold" sheet. Rows with the same invoice (or, without one, the same
 * date, time, staff, customer and payment) are one sale. Each sale is rung up like Billing/POS does
 * (SaleRecorder: stock taken, paid in full) and dated when it was sold; a sale already in the system
 * (its invoice) is skipped, so importing a downloaded report again adds nothing.
 */
class SaleImport
{
    public const COLUMNS = ['Date', 'Time', 'Invoice', 'Staff', 'Customer', 'Item', 'Size or type', 'SKU', 'Quantity', 'Unit price', 'Paid by'];
    private const MAX_ROWS = 3000;

    public function __construct(private SaleRecorder $recorder)
    {
    }

    // The item rows of an .xlsx (its "Items sold" sheet, or the first) or a .csv, keyed by heading,
    // each with its row number in the file.
    public function rows(string $path, string $extension): array
    {
        if ($extension === 'csv') {
            $handle = fopen($path, 'r');
            $all = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false && count($all) <= self::MAX_ROWS + 50) {
                $all[] = $row;
            }
            fclose($handle);
            if (isset($all[0][0])) {
                $all[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $all[0][0]);   // the mark Excel needs
            }
        } else {
            $sheets = Xlsx::read($path);
            $all = $sheets['Items sold'] ?? (reset($sheets) ?: []);
        }

        // The headings are the first row with Item and Quantity in it: a downloaded CSV has other tables before it.
        $head = null;
        foreach ($all as $i => $row) {
            $names = array_map(fn ($v) => strtolower(trim((string) $v)), $row);
            if (in_array('item', $names, true) && in_array('quantity', $names, true)) {
                $head = [$i, $names];
                break;
            }
        }
        if (! $head) {
            throw new RuntimeException('The file needs a heading row with at least Date, Item, Quantity and Unit price. Download the template to see how.');
        }

        $rows = [];
        foreach (array_slice($all, $head[0] + 1, null, true) as $i => $row) {
            $values = array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '');
            if (! $values) {
                if ($extension === 'csv') {
                    break;   // the end of this table in a downloaded CSV
                }
                continue;
            }
            if (strtolower(trim((string) ($row[0] ?? ''))) === 'total') {
                continue;
            }
            $cells = ['line' => $i + 1];
            foreach (self::COLUMNS as $column) {
                $at = array_search(strtolower($column), $head[1], true);
                $cells[$column] = $at === false ? null : (is_string($row[$at] ?? null) ? trim($row[$at]) : ($row[$at] ?? null));
            }
            $rows[] = $cells;
            if (count($rows) > self::MAX_ROWS) {
                throw new RuntimeException('That file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
            }
        }

        return $rows;
    }

    /**
     * What the rows would add: ['sales' => [...], 'skipped' => ["Row 7: ...", ...]]. A sale goes in
     * whole or not at all, so one bad row skips its whole sale.
     */
    public function plan(array $rows, User $importer): array
    {
        $products = Product::with(['variants' => fn ($q) => $q->where('status', 'active')])->where('status', 'active')->get();
        $bySku = [];
        foreach ($products as $p) {
            if ($p->sku) {
                $bySku[strtolower($p->sku)] = [$p, null];
            }
            foreach ($p->variants as $v) {
                if ($v->sku) {
                    $bySku[strtolower($v->sku)] = [$p, $v];
                }
            }
        }
        $byName = $products->keyBy(fn ($p) => strtolower($p->name));
        $staff = User::withTrashed()->get(['id', 'name'])->keyBy(fn ($u) => strtolower($u->name));

        $sales = [];
        $bad = [];
        $skipped = [];
        foreach ($rows as $row) {
            $line = $row['line'];
            [$error, $at, $product, $variant, $quantity, $price] = [null, null, null, null, 0, 0];
            try {
                $at = $this->when($row['Date'], $row['Time']);
                $quantity = (float) $row['Quantity'];
                if ($quantity < 1 || floor($quantity) != $quantity) {
                    throw new RuntimeException('Quantity must be a whole number, 1 or more.');
                }
                $price = round((float) str_replace([',', '₱'], '', (string) $row['Unit price']), 2);
                if ($price <= 0) {
                    throw new RuntimeException('Unit price must be more than 0.');
                }
                [$product, $variant] = $this->item($row, $bySku, $byName);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }

            $person = $staff[strtolower((string) $row['Staff'])] ?? null;
            $method = preg_match('/g\s*cash/i', (string) $row['Paid by']) ? 'gcash' : 'cash';
            $invoice = trim((string) $row['Invoice']);
            $key = $invoice !== '' ? "invoice:{$invoice}" : implode('|', [$at ?? $line, $row['Staff'], $row['Customer'], $method]);
            if ($error) {
                $bad[$key][] = "Row {$line}: {$error}";
                continue;
            }

            $sales[$key] ??= [
                'invoice' => $invoice !== '' ? $invoice : null, 'at' => $at, 'method' => $method,
                'staff_id' => $person?->id ?? $importer->id, 'staff' => $person?->name ?? $importer->name,
                'customer' => trim((string) $row['Customer']) ?: null, 'lines' => [], 'total' => 0.0, 'rows' => [],
            ];
            $sales[$key]['lines'][] = [
                'product_id' => $product->id, 'variant_id' => $variant?->id, 'quantity' => (int) $quantity, 'price' => $price,
                'name' => $product->name . ($variant ? " ({$variant->variant_name})" : ''),
            ];
            $sales[$key]['total'] = round($sales[$key]['total'] + $quantity * $price, 2);
            $sales[$key]['rows'][] = $line;
        }

        foreach ($bad as $key => $errors) {
            array_push($skipped, ...$errors);
            if (isset($sales[$key])) {
                $skipped[] = 'So ' . ($sales[$key]['invoice'] ?? 'that sale') . ' (rows ' . implode(', ', $sales[$key]['rows']) . ') was left out too: a sale goes in whole.';
                unset($sales[$key]);
            }
        }
        foreach ($sales as $key => $sale) {
            if ($sale['invoice'] && $this->alreadyIn($sale['invoice'])) {
                $skipped[] = "{$sale['invoice']} is already in the system, so it was left out.";
                unset($sales[$key]);
            }
        }

        return ['sales' => array_values(array_map(fn ($s) => array_diff_key($s, ['rows' => 1]), $sales)), 'skipped' => $skipped];
    }

    /**
     * Rings up one planned sale (plan()'s shape, checked again by the controller) as its staff, dated
     * when it was sold, paid in full; completed unless something on it is out of stock (on order).
     * It joins no shift: that shift's cash count is long done.
     */
    public function run(array $sale): Sale
    {
        if ($sale['invoice'] && $this->alreadyIn($sale['invoice'])) {
            throw new RuntimeException("{$sale['invoice']} is already in the system.");
        }
        $at = CarbonImmutable::parse($sale['at']);
        if ($at->isFuture()) {
            throw new RuntimeException('A sale from the future can\'t be imported.');
        }
        $lines = array_map(fn ($l) => ['product_id' => $l['product_id'], 'product_variant_id' => $l['variant_id'] ?? null,
            'quantity' => (int) $l['quantity'], 'unit_price' => (float) $l['price']], $sale['lines']);
        $total = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));

        // Everything this sale writes (its payment, the stock it takes) is dated when it was sold.
        Carbon::setTestNow($at);
        try {
            return DB::transaction(function () use ($sale, $lines, $total) {
                [$created, $onOrder] = $this->recorder->ring([
                    'items' => $lines, 'new_customer_name' => $sale['customer'] ?? null, 'payment_method' => $sale['method'],
                    'amount_paid' => $total, 'discount_amount' => 0,
                    'notes' => 'Imported from a sheet' . ($sale['invoice'] ? " (sheet invoice {$sale['invoice']})" : '') . '.',
                ], User::withTrashed()->findOrFail($sale['staff_id']));
                $created->forceFill(['shift_id' => null, 'status' => $created->status === 'processing' && ! $onOrder ? 'completed' : $created->status])->save();
                $created->payments()->update(['shift_id' => null]);

                return $created;
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    // In the system already: its own invoice number, or imported before under that sheet invoice.
    private function alreadyIn(string $invoice): bool
    {
        return Sale::where('invoice_number', $invoice)->orWhere('notes', 'like', '%(sheet invoice ' . addcslashes($invoice, '%_\\') . ')%')->exists();
    }

    private function when($date, $time): string
    {
        if ($date === null || $date === '') {
            throw new RuntimeException('Date is missing.');
        }
        $day = is_float($date) ? Xlsx::date($date) : (strtotime((string) $date) ? date('Y-m-d H:i:s', strtotime((string) $date)) : null);
        if (! $day) {
            throw new RuntimeException("\"{$date}\" isn't a date. Write it like " . now()->format('Y-m-d') . '.');
        }
        $clock = match (true) {
            is_float($time) => substr(Xlsx::date(fmod($time, 1) + 25569), 11, 5),
            is_string($time) && $time !== '' && strtotime("2000-01-01 {$time}") => date('H:i', strtotime("2000-01-01 {$time}")),
            $time === null || $time === '' => substr($day, 11, 5) !== '00:00' ? substr($day, 11, 5) : '12:00',
            default => throw new RuntimeException("\"{$time}\" isn't a time. Write it like 2:30 PM."),
        };
        $at = substr($day, 0, 10) . " {$clock}:00";
        if (CarbonImmutable::parse($at)->isFuture()) {
            throw new RuntimeException("{$at} hasn't happened yet.");
        }

        return $at;
    }

    // The catalog item of a row: by SKU, or by item name and size.
    private function item(array $row, array $bySku, $byName): array
    {
        $sku = strtolower((string) $row['SKU']);
        if ($sku !== '' && isset($bySku[$sku])) {
            [$product, $variant] = $bySku[$sku];
            if (! $variant && $product->variants->count() === 1) {
                $variant = $product->variants->first();
            }

            return [$product, $variant];
        }
        $product = $byName[strtolower((string) $row['Item'])] ?? null;
        if (! $product) {
            throw new RuntimeException('No product called "' . ($row['Item'] ?: '(blank)') . '"' . ($sku !== '' ? " or with SKU {$row['SKU']}" : '') . '. Check the name or SKU on the Products page.');
        }
        $size = strtolower((string) $row['Size or type']);
        $variant = $size !== '' ? $product->variants->first(fn ($v) => strtolower($v->variant_name) === $size) : null;
        if (! $variant && $product->variants->count() === 1 && $size === '') {
            $variant = $product->variants->first();
        }
        if (! $variant && $product->variants->isNotEmpty()) {
            throw new RuntimeException("{$product->name} has no size or type called \"" . ($row['Size or type'] ?: '(blank)') . '". It has: '
                . $product->variants->pluck('variant_name')->implode(', ') . '.');
        }

        return [$product, $variant];
    }
}
