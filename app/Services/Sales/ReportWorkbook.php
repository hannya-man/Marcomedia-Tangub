<?php
namespace App\Services\Sales;

use App\Support\Xlsx;

/**
 * The sales report as an Excel workbook that works like a second dashboard: a Dashboard sheet first
 * (its totals are formulas over the sheets behind it, with charts and the top items and staff), then
 * one sheet per table with coloured headings, peso amounts, real dates, filters and a Total row that
 * follows the filter (SUBTOTAL).
 */
class ReportWorkbook
{
    // How a column shows, by its heading.
    private const MONEY = ['Total', 'Discount', 'Unit price', 'Line total', 'Revenue', 'Average sale', 'Discounts given', 'Amount', 'Cost',
        'Material cost', 'Scrap sold for', 'Sales total', 'Revenue (before discounts)'];
    private const COUNTS = ['Items', 'Sales', 'Payments', 'Items sold', 'Quantity'];
    private const NOT_ADDED_UP = ['Unit price', 'Average sale'];

    // Columns of the Dashboard sheet (A to L), and each KPI tile's first column.
    private const COLUMNS = 12;
    private const TILES = [0, 3, 6, 9];

    public function __construct(private SalesReport $report, private string $reportUrl, private string $importUrl)
    {
    }

    public function download(string $filename)
    {
        return Xlsx::download($filename, $this->sheets());
    }

    public function sheets(): array
    {
        $tables = [];
        foreach (array_keys(SalesReport::TABLES) as $key) {
            if ($key !== 'summary') {
                $tables[$key] = $this->report->table($key);
            }
        }
        $sheets = ['Dashboard' => $this->dashboard($tables)];
        foreach ($tables as $key => $rows) {
            $sheets[SalesReport::TABLES[$key]] = $this->dataSheet($rows, $key === 'low_stock');
        }

        return $sheets;
    }

    private function dataSheet(array $rows, bool $lowStock): array
    {
        $head = array_shift($rows);
        $hasUnit = in_array('Unit', $head, true);
        $out = [array_map(fn ($h) => ['v' => $h, 's' => 'header'], $head)];
        foreach ($rows as $row) {
            $line = [];
            foreach ($head as $c => $h) {
                $v = $row[$c] ?? null;
                $line[] = match (true) {
                    $v === null || $v === '' => ['v' => null, 's' => 'text'],
                    $h === 'Date' => ['v' => Xlsx::serial((string) $v), 's' => 'date'],
                    $h === 'Time' => ['v' => round(fmod(Xlsx::serial('1970-01-01 ' . date('H:i', strtotime((string) $v))), 1), 6), 's' => 'time'],
                    in_array($h, self::MONEY, true) => ['v' => (float) $v, 's' => 'money'],
                    in_array($h, self::COUNTS, true) && ! $hasUnit => ['v' => (int) $v, 's' => 'int'],
                    is_int($v) || is_float($v) => ['v' => $v, 's' => 'qty'],
                    $lowStock && $h === 'Status' => ['v' => $v, 's' => $v === 'Out' ? 'bad' : 'warn'],
                    default => ['v' => $v, 's' => 'text'],
                };
            }
            $out[] = $line;
        }

        $last = count($out);
        if (! $lowStock && $rows) {
            $total = [];
            foreach ($head as $c => $h) {
                $money = in_array($h, self::MONEY, true);
                $adds = ($money || (in_array($h, self::COUNTS, true) && ! $hasUnit)) && ! in_array($h, self::NOT_ADDED_UP, true);
                $col = Xlsx::column($c);
                $total[] = match (true) {
                    $c === 0 => ['v' => 'Total', 's' => 'total'],
                    $adds => ['f' => "SUBTOTAL(109,{$col}2:{$col}{$last})", 'v' => array_sum(array_map(fn ($line) => (float) ($line[$c]['v'] ?? 0), array_slice($out, 1))), 's' => $money ? 'total_money' : 'total_int'],
                    default => ['v' => null, 's' => 'total'],
                };
            }
            $out[] = $total;
        }
        $widths = [];
        foreach ($head as $c => $h) {
            if (in_array($h, ['Date', 'Time'], true)) {
                $widths[$c] = $h === 'Date' ? 14 : 11;
            }
        }

        return ['rows' => $out, 'freeze' => true, 'filter' => 'A1:' . Xlsx::column(count($head) - 1) . $last, 'widths' => $widths, 'tab' => '1F8BA0'];
    }

    private function dashboard(array $t): array
    {
        $r = $this->report;
        $rows = array_fill(0, 62, []);
        $set = function (int $row, int $col, array $cell) use (&$rows) {   // 1-based row, 0-based column
            $rows[$row - 1] = array_replace(array_fill(0, max($col + 1, count($rows[$row - 1])), null), $rows[$row - 1], [$col => $cell]);
        };
        $merge = [];
        $span = function (int $row, int $from, int $to, array $cell) use ($set, &$merge) {
            for ($c = $from; $c <= $to; $c++) {
                $set($row, $c, $c === $from ? $cell : ['v' => null, 's' => $cell['s']]);
            }
            if ($to > $from) {
                $merge[] = Xlsx::column($from) . $row . ':' . Xlsx::column($to) . $row;
            }
        };
        $sum = fn (string $key, string $heading) => array_sum(array_map(fn ($row) => (float) ($row[array_search($heading, $t[$key][0], true)] ?? 0), array_slice($t[$key], 1)));
        $range = function (string $key, string $heading) use ($t) {   // where a column's values are, without the Total row
            $col = Xlsx::column(array_search($heading, $t[$key][0], true));
            $end = max(count($t[$key]), 2);

            return Xlsx::quote(SalesReport::TABLES[$key]) . "!\${$col}\$2:\${$col}\${$end}";
        };
        $money = fn ($v) => '₱' . number_format((float) $v, 2);

        // Title, what this is, and links back to the app.
        $span(1, 0, 11, ['v' => 'Marcomedia · Sales Report', 's' => 'title']);
        $span(2, 0, 11, ['v' => $r->label() . ' · ' . ($r->staffName() ?? 'Everyone') . ' · made ' . now()->format('M j, Y g:i A'), 's' => 'subtitle']);
        $link = fn (string $url, string $text) => ['f' => 'HYPERLINK("' . str_replace('"', '""', $url) . '","' . $text . '")', 'v' => $text, 's' => 'link'];
        $span(3, 0, 5, $link($this->reportUrl, 'Open this report in Marcomedia POS'));
        $span(3, 6, 11, $link($this->importUrl, 'Import sales from a sheet (Sales Report > Import)'));

        $payments = collect(array_slice($t['payments'], 1))->mapWithKeys(fn ($p) => [$p[0] => $p[2]]);
        $materials = collect(array_slice($t['materials'], 1));
        $rejected = array_slice($t['rejected'], 1);
        $low = array_slice($t['low_stock'], 1);
        $count = count($t['sales']) - 1;
        $total = $sum('sales', 'Total');
        $tiles = [
            [5, 'Sales total', 'SUM(' . $range('sales', 'Total') . ')', $total, 'kpi_money', $money($sum('sales', 'Discount')) . ' in discounts'],
            [5, 'Completed sales', 'COUNTA(' . $range('sales', 'Invoice') . ')', $count, 'kpi_int', $r->period === 'day' ? 'today' : 'in this ' . $r->period],
            [5, 'Average sale', 'IFERROR(A6/D6,0)', $count ? $total / $count : 0, 'kpi_money', 'per completed sale'],
            [5, 'Items sold', 'SUM(' . $range('items', 'Quantity') . ')', (int) $sum('items', 'Quantity'), 'kpi_int', 'pieces, every line'],
            [9, 'Money received', 'SUM(' . $range('payments', 'Amount') . ')', $payments->sum(), 'kpi_money',
                'Cash ' . $money($payments['Cash'] ?? 0) . ' · GCash ' . $money($payments['GCash'] ?? 0)],
            [9, 'Materials used', 'SUM(' . $range('materials', 'Cost') . ')', $materials->sum(4), 'kpi_money',
                'continuous ' . $money($materials->where(0, 'Continuous raw material')->sum(4)) . ' · discrete ' . $money($materials->where(0, '!=', 'Continuous raw material')->sum(4))],
            [9, 'Material lost', 'SUM(' . $range('waste', 'Cost') . ')', $sum('waste', 'Cost'), 'kpi_money',
                count($rejected) . ' rejected ' . (count($rejected) === 1 ? 'run' : 'runs') . ', ' . $money(array_sum(array_column($rejected, 7))) . ' back from scrap'],
            [9, 'Running low now', 'COUNTA(' . $range('low_stock', 'Item or material') . ')', count($low), 'kpi_int',
                count(array_filter($low, fn ($row) => $row[5] === 'Out')) . ' of them out of stock'],
        ];
        foreach ($tiles as $i => [$row, $label, $formula, $value, $style, $note]) {
            $from = self::TILES[$i % 4];
            $span($row, $from, $from + 2, ['v' => $label, 's' => 'kpi_label']);
            $span($row + 1, $from, $from + 2, ['f' => $formula, 'v' => $value, 's' => $style]);
            $span($row + 2, $from, $from + 2, ['v' => $note, 's' => 'kpi_note']);
        }

        // Charts: sales over time across the page, then by category and by staff side by side.
        $charts = [];
        $series = array_slice($t['by_time'], 1);
        $set(13, 0, ['v' => ['day' => 'Sales by hour', 'year' => 'Sales by month'][$r->period] ?? 'Sales by day', 's' => 'section']);
        $charts[] = ['type' => 'bar', 'title' => 'Sales total', 'money' => true, 'from' => [0, 13], 'to' => [12, 30],
            'labels' => array_column($series, 0), 'values' => array_column($series, 1),
            'labelsRef' => $range('by_time', $t['by_time'][0][0]), 'valuesRef' => $range('by_time', 'Sales total')];
        $set(32, 0, ['v' => 'By category', 's' => 'section']);
        $set(32, 6, ['v' => 'By staff', 's' => 'section']);
        $categories = array_slice($t['categories'], 1);
        $people = array_slice($t['staff'], 1);
        if ($categories) {
            $charts[] = ['type' => 'doughnut', 'title' => 'Revenue by category', 'from' => [0, 32], 'to' => [6, 49],
                'labels' => array_column($categories, 0), 'values' => array_column($categories, 1),
                'labelsRef' => $range('categories', 'Category'), 'valuesRef' => $range('categories', 'Revenue (before discounts)')];
        } else {
            $set(34, 0, ['v' => 'Nothing sold.', 's' => 'kpi_note']);
        }
        if ($people) {
            $charts[] = ['type' => 'bar', 'horizontal' => true, 'title' => 'Revenue by staff', 'money' => true, 'color' => 'E0A526', 'from' => [6, 32], 'to' => [12, 49],
                'labels' => array_column($people, 0), 'values' => array_column($people, 3),
                'labelsRef' => $range('staff', 'Staff'), 'valuesRef' => $range('staff', 'Revenue')];
        } else {
            $set(34, 6, ['v' => 'Nothing sold.', 's' => 'kpi_note']);
        }

        // The top 10 items and the staff, linked to their sheets.
        $set(51, 0, ['v' => 'Top items', 's' => 'section']);
        $set(51, 6, ['v' => 'Staff', 's' => 'section']);
        $span(52, 0, 2, ['v' => 'Item', 's' => 'header']);
        foreach ([[3, 'Size or type'], [4, 'Sold'], [5, 'Revenue']] as [$c, $h]) {
            $set(52, $c, ['v' => $h, 's' => 'header']);
        }
        $span(52, 6, 8, ['v' => 'Staff', 's' => 'header']);
        foreach ([[9, 'Sales'], [10, 'Items'], [11, 'Revenue']] as [$c, $h]) {
            $set(52, $c, ['v' => $h, 's' => 'header']);
        }
        $ref = fn (string $key, int $col, int $row) => Xlsx::quote(SalesReport::TABLES[$key]) . '!' . Xlsx::column($col) . ($row + 2);
        foreach (array_slice($t['by_item'], 1, 10) as $i => $item) {   // Item, Size or type, SKU, Quantity, Revenue
            $span(53 + $i, 0, 2, ['f' => $ref('by_item', 0, $i), 'v' => $item[0], 's' => 'text']);
            $set(53 + $i, 3, ['f' => $ref('by_item', 1, $i) . '&""', 'v' => (string) $item[1], 's' => 'text']);
            $set(53 + $i, 4, ['f' => $ref('by_item', 3, $i), 'v' => (int) $item[3], 's' => 'int']);
            $set(53 + $i, 5, ['f' => $ref('by_item', 4, $i), 'v' => (float) $item[4], 's' => 'money']);
        }
        foreach (array_slice($t['staff'], 1, 10) as $i => $person) {   // Staff, Sales, Items sold, Revenue
            $span(53 + $i, 6, 8, ['f' => $ref('staff', 0, $i), 'v' => $person[0], 's' => 'text']);
            $set(53 + $i, 9, ['f' => $ref('staff', 1, $i), 'v' => (int) $person[1], 's' => 'int']);
            $set(53 + $i, 10, ['f' => $ref('staff', 2, $i), 'v' => (int) $person[2], 's' => 'int']);
            $set(53 + $i, 11, ['f' => $ref('staff', 3, $i), 'v' => (float) $person[3], 's' => 'money']);
        }

        return [
            'rows' => $rows, 'merge' => $merge, 'charts' => $charts, 'grid' => false, 'tab' => '146C84',
            'widths' => array_fill(0, self::COLUMNS, 13.5),
            'heights' => [1 => 36, 2 => 20, 3 => 22, 4 => 8, 5 => 18, 6 => 32, 7 => 18, 8 => 10, 9 => 18, 10 => 32, 11 => 18, 12 => 10],
        ];
    }
}
