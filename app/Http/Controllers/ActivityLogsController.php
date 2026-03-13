<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Exports\ActivityLogsExport;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ActivityLogsController extends Controller
{
    /**
     * Build base query with optional filters (date_from, date_to, user_id, model, action).
     */
    protected function queryWithFilters(Request $request)
    {
        $query = ActivityLog::with('user')->select('activity_logs.*');

        if ($request->filled('date_from')) {
            $query->whereDate('activity_logs.activity_time', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('activity_logs.activity_time', '<=', $request->date_to);
        }
        if ($request->filled('user_id')) {
            $query->where('activity_logs.user_id', $request->user_id);
        }
        if ($request->filled('model')) {
            $query->where('activity_logs.model', $request->model);
        }
        if ($request->filled('action')) {
            $query->where('activity_logs.action', $request->action);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $totalLogs = ActivityLog::count();
        $filteredCount = $this->queryWithFilters($request)->count();

        $userIds = ActivityLog::whereNotNull('user_id')->distinct()->pluck('user_id');
        $users = User::whereIn('id', $userIds)->excludeSuperAdmin()->orderBy('name')->get(['id', 'name']);
        $models = ActivityLog::select('model')
            ->whereNotNull('model')
            ->distinct()
            ->orderBy('model')
            ->pluck('model');
        $actions = ActivityLog::select('action')
            ->whereNotNull('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('logs.index', compact(
            'totalLogs',
            'filteredCount',
            'users',
            'models',
            'actions'
        ));
    }

    /**
     * DataTables AJAX endpoint for activity logs
     */
    public function getData(Request $request)
    {
        if ($request->ajax()) {
            $query = $this->queryWithFilters($request);

            return DataTables::eloquent($query)
                ->addColumn('user_name', function (ActivityLog $log) {
                    return $log->user->name ?? 'Guest';
                })
                ->addColumn('changes_summary', function (ActivityLog $log) {
                    return $log->changes_summary ?? '';
                })
                ->editColumn('activity_time', function (ActivityLog $log) {
                    // Show full timestamp with seconds
                    return optional($log->activity_time)->format('Y-m-d H:i:s') ?? $log->created_at->format('Y-m-d H:i:s');
                })
                ->editColumn('action', function (ActivityLog $log) {
                    return ucfirst(str_replace('_', ' ', $log->action));
                })
                ->orderColumn('activity_time', function ($query, $order) {
                    $query->orderBy('activity_time', $order)->orderBy('id', $order);
                })
                ->make(true);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }

    /**
     * Export activity logs to Excel (respects current filters).
     */
    public function exportExcel(Request $request)
    {
        $query = $this->queryWithFilters($request)
            ->orderBy('activity_logs.activity_time', 'desc')
            ->orderBy('activity_logs.id', 'desc');
        $logs = $query->get();

        $fileName = 'activity-logs-' . now()->format('Y-m-d-His') . '.xlsx';
        return Excel::download(new ActivityLogsExport($logs), $fileName);
    }

    /**
     * Export activity logs to PDF (respects current filters).
     */
    public function exportPdf(Request $request)
    {
        $query = $this->queryWithFilters($request)
            ->orderBy('activity_logs.activity_time', 'desc')
            ->orderBy('activity_logs.id', 'desc');
        $logs = $query->limit(500)->get(); // Limit for PDF size

        $company = auth()->user()->company ?? null;
        $pdf = Pdf::loadView('logs.export-pdf', compact('logs', 'company'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('activity-logs-' . now()->format('Y-m-d-His') . '.pdf');
    }

    public function show($id)
    {
        $log = ActivityLog::with('user')->findOrFail($id);
        return view('activity_logs.show', compact('log'));
    }
}
