<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Sales\ReportWorkbook;
use App\Services\Sales\SalesReport;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// The sales report (owner only): a day, week, month or year, for everyone or one person, on the page,
// as an Excel or CSV file, or brought into Google Sheets through an owner's secret link.
class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $report = $this->report($request);

        return view('reports.sales', [
            'report' => $report,
            'sales' => $report->salesList()->paginate(25)->withQueryString(),
            'staff' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
            'sheetLinkOn' => $request->user()->sheet_token !== null,
        ]);
    }

    public function download(Request $request)
    {
        $request->validate(['format' => 'nullable|in:xlsx,csv']);
        $report = $this->report($request);
        $name = 'sales-report-' . $report->period . '-' . $report->start->toDateString()
            . ($report->userId ? '-' . Str::slug((string) $report->staffName()) : '');

        if ($request->query('format', 'xlsx') === 'xlsx') {
            $here = array_filter(['period' => $report->period, 'date' => $report->start->toDateString(), 'user' => $report->userId]);

            return (new ReportWorkbook($report, route('reports.sales', $here), route('reports.sales', $here) . '#import'))->download("{$name}.xlsx");
        }
        $rows = [];
        foreach ($report->tables() as $title => $table) {
            array_push($rows, [$title], ...$table);
            $rows[] = [];
        }

        return Csv::download("{$name}.csv", $rows);
    }

    // A new secret link for Google Sheets (=IMPORTDATA). Only its hash is kept, so the link is shown
    // this once; a new one turns the old one off.
    public function makeSheetLink(Request $request)
    {
        $token = Str::random(40);
        $request->user()->forceFill(['sheet_token' => hash('sha256', $token)])->save();

        return back()->with('success', 'Made a new Google Sheets link. Copy it now: it is shown only this once. Any older link stopped working.')
            ->with('sheet_link', route('reports.sheet', $token));
    }

    public function stopSheetLink(Request $request)
    {
        $request->user()->forceFill(['sheet_token' => null])->save();

        return back()->with('success', 'Turned off the Google Sheets link. Sheets that used it stop updating.');
    }

    // What Google Sheets fetches (no login): one table of the report for the current day, week, month
    // or year, as CSV. The link works while its owner is still an owner.
    public function sheet(Request $request, string $token)
    {
        $owner = User::where('sheet_token', hash('sha256', $token))->first();
        $table = $request->query('table', 'items');
        $period = $request->query('period', 'month');
        abort_unless($owner?->isAdmin() && array_key_exists($table, SalesReport::TABLES) && array_key_exists($period, SalesReport::PERIODS), 404);

        $report = new SalesReport($period, CarbonImmutable::today());

        $response = Csv::download("{$table}.csv", $report->table($table), forExcel: false);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function report(Request $request): SalesReport
    {
        $request->validate(['period' => 'nullable|in:day,week,month,year', 'date' => 'nullable|date', 'user' => 'nullable|integer']);

        return new SalesReport($request->query('period', 'day'), CarbonImmutable::parse($request->query('date', 'today')),
            $request->integer('user') ?: null);
    }
}
