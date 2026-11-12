<?php
namespace App\Http\Controllers;

use App\Services\Sales\SalesReport;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

// The sales report (owner only): a day, week, month or year, on the page or as a spreadsheet.
class ReportController extends Controller
{
    public function sales(Request $request)
    {
        return view('reports.sales', ['report' => $this->report($request)]);
    }

    public function download(Request $request)
    {
        $report = $this->report($request);

        return Csv::download("sales-report-{$report->period}-{$report->start->toDateString()}.csv", $report->rows());
    }

    private function report(Request $request): SalesReport
    {
        $request->validate(['period' => 'nullable|in:day,week,month,year', 'date' => 'nullable|date']);

        return new SalesReport($request->query('period', 'day'), CarbonImmutable::parse($request->query('date', 'today')));
    }
}
