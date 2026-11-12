<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;

// Everyone's activity (owner only): what each person did and when. "Needs a look" is the part an
// owner should check: wrong passwords, cash differences, voids, returns, stock written off.
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $logs = $this->filtered($request)->with('user')->latest('id')->paginate(30)->withQueryString();
        $people = User::withTrashed()->whereIn('id', ActivityLog::select('user_id')->whereNotNull('user_id'))->orderBy('name')->get();
        $needsReviewCount = ActivityLog::where('needs_review', true)->count();

        return view('activity.index', compact('logs', 'people', 'needsReviewCount'));
    }

    public function download(Request $request)
    {
        $logs = $this->filtered($request)->with('user')->latest('id')->limit(10000)->get();
        $rows = [['When', 'Who', 'What happened', 'Needs a look', 'From (IP address)']];
        foreach ($logs as $log) {
            $rows[] = [$log->created_at?->format('Y-m-d H:i:s'), $log->user->name ?? 'No account', $log->description, $log->needs_review ? 'Yes' : '', $log->ip_address];
        }

        return Csv::download('activity-log-' . now()->format('Y-m-d') . '.csv', $rows);
    }

    private function filtered(Request $request)
    {
        $request->validate(['date_from' => 'nullable|date', 'date_to' => 'nullable|date', 'user' => 'nullable|integer']);

        return ActivityLog::query()
            ->when($request->query('tab') === 'review', fn ($q) => $q->where('needs_review', true))
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->query('user')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('date_to')));
    }
}
