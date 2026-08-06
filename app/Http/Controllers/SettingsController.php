<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Branch;
use App\Models\JobLog;
use App\Models\Backup;
use App\Models\DatabaseImport;
use App\Services\BackupService;
use App\Services\AiAssistantService;
use App\Jobs\BackupJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;
use App\Jobs\AccruePenaltyJob;
use App\Jobs\CalculateDailyInterestJob;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ArrearsClassification;
use App\Models\SmsLog;
use App\Support\Upload\FileUploadLimits;

class SettingsController extends Controller
{
    private function authorizeArrearsClassifications(): void
    {
        // Reuse existing permission to avoid migration/seed changes.
        if (!auth()->user()->hasRole('super-admin') && !auth()->user()->can('manage penalty setting')) {
            abort(403, 'You do not have permission to manage arrears classifications.');
        }
    }

    public function __construct()
    {
        // Middleware is applied in routes/web.php
    }

    public function index()
    {
        $company = Company::find(current_company_id());
        $branches = Branch::forCompany()->active()->get();

        return view('settings.index', compact('company', 'branches'));
    }

    public function companySettings()
    {
        $company = Company::find(current_company_id());

        return view('settings.company', compact('company'));
    }

    public function updateCompanySettings(Request $request)
    {
        $company = Company::find(current_company_id());

        // Custom validation for email to handle existing email
        $emailRules = 'required|email';
        if ($request->email !== $company->email) {
            $emailRules .= '|unique:companies,email,' . $company->id . ',id';
        }

        // Custom validation for license_number to handle existing license
        $licenseRules = 'required|string';
        if ($request->license_number !== $company->license_number) {
            $licenseRules .= '|unique:companies,license_number,' . $company->id . ',id';
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => $emailRules,
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'license_number' => $licenseRules,
            'registration_date' => 'required|date',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'bg_color' => 'nullable|string|max:7',
            'txt_color' => 'nullable|string|max:7',
        ]);

        $data = $request->except('logo');

        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            if ($company->logo && Storage::disk('public')->exists($company->logo)) {
                Storage::disk('public')->delete($company->logo);
            }

            $logo = $request->file('logo');
            $logoName = 'company_' . $company->id . '_' . time() . '.' . $logo->getClientOriginalExtension();
            $logoPath = $logo->storeAs('uploads/companies', $logoName, 'public');
            $data['logo'] = $logoPath;
        }

        $company->update($data);

        return redirect()->route('settings.company')->with('success', 'Company settings updated successfully!');
    }

    public function branchSettings()
    {
        return view('settings.branches');
    }

    /**
     * Branch list for DataTables (AJAX)
     */
    public function branchSettingsData(Request $request)
    {
        $query = Branch::forCompany();

        return \Yajra\DataTables\Facades\DataTables::of($query)
            ->editColumn('branch_name', function (Branch $branch) {
                // Fallback to 'name' for older records where branch_name is null
                return $branch->branch_name ?: $branch->name;
            })
            ->addColumn('status_badge', function (Branch $branch) {
                if ($branch->status === 'active') {
                    return '<span class="badge bg-success">Active</span>';
                }
                if ($branch->status === 'inactive') {
                    return '<span class="badge bg-warning">Inactive</span>';
                }
                return '<span class="badge bg-danger">Suspended</span>';
            })
            ->addColumn('actions', function (Branch $branch) {
                $editUrl = route('settings.branches.edit', $branch);
                $deleteUrl = route('settings.branches.destroy', $branch);

                return view('settings.branches._actions', compact('branch', 'editUrl', 'deleteUrl'))->render();
            })
            ->rawColumns(['status_badge', 'actions'])
            ->make(true);
    }

    public function createBranch()
    {
        return view('settings.branches.create');
    }

    public function storeBranch(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:branches,email,NULL,id,company_id,' . current_company_id(),
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'location' => 'nullable|string',
            'manager_name' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $branch = Branch::create([
            'company_id' => current_company_id(),
            'name' => $request->name,
            'branch_name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'location' => $request->location,
            'manager_name' => $request->manager_name,
            'branch_id' => \Illuminate\Support\Str::uuid(),
            'status' => $request->status,
        ]);

        return redirect()->route('settings.branches')->with('success', 'Branch created successfully!');
    }

    public function editBranch(Branch $branch)
    {
        // Ensure branch belongs to current company
        if ($branch->company_id !== current_company_id()) {
            abort(403, 'Unauthorized access.');
        }

        return view('settings.branches.edit', compact('branch'));
    }

    public function updateBranch(Request $request, Branch $branch)
    {
        // Ensure branch belongs to current company
        if ($branch->company_id !== current_company_id()) {
            abort(403, 'Unauthorized access.');
        }

        // Custom validation for email to handle existing email
        $emailRules = 'nullable|email';
        if ($request->email !== $branch->email) {
            $emailRules .= '|unique:branches,email,' . $branch->id . ',id,company_id,' . current_company_id();
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => $emailRules,
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'location' => 'nullable|string',
            'manager_name' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $data = $request->all();
        // Keep branch_name in sync with name now that Display Name is removed from the form
        $data['branch_name'] = $request->input('name');

        $branch->update($data);

        return redirect()->route('settings.branches')->with('success', 'Branch updated successfully!');
    }

    public function destroyBranch(Branch $branch)
    {
        // Ensure branch belongs to current company
        if ($branch->company_id !== current_company_id()) {
            abort(403, 'Unauthorized access.');
        }

        // Check if branch has users
        if ($branch->users()->count() > 0) {
            return redirect()->route('settings.branches')->with('error', 'Cannot delete branch with active users.');
        }

        $branch->delete();

        return redirect()->route('settings.branches')->with('success', 'Branch deleted successfully!');
    }

    public function userSettings()
    {
        $user = auth()->user();
        $user->load(['branch', 'company', 'roles']);

        return view('settings.user', compact('user'));
    }

    public function updateUserSettings(Request $request)
    {
        $user = auth()->user();

        // Custom validation for email to handle existing email
        $emailRules = 'nullable|email';
        if ($request->email !== $user->email) {
            $emailRules .= '|unique:users,email,' . $user->id . ',id,company_id,' . current_company_id();
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:users,phone,' . $user->id . ',id,company_id,' . current_company_id(),
            'email' => $emailRules,
            'current_password' => 'nullable|required_with:new_password',
            'new_password' => 'nullable|string|min:8|confirmed',
        ]);

        // Verify current password if changing password
        if ($request->filled('new_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return back()->withErrors(['current_password' => 'Current password is incorrect.']);
            }
        }

        $userData = [
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
        ];

        if ($request->filled('new_password')) {
            $userData['password'] = Hash::make($request->new_password);
        }

        $user->update($userData);

        return redirect()->route('settings.user')->with('success', 'User settings updated successfully!');
    }

    public function systemSettings()
    {
        // Check permissions for system configurations
        if (!auth()->user()->can('view system configurations') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to view system configurations.');
        }

        $groups = [
            'general'       => 'General Settings',
            'email'         => 'Email Configuration',
            'security'      => 'Security Settings',
            'microfinance'  => 'Loans & Microfinance',
            'backup'        => 'Backup Configuration',
            'maintenance'   => 'Maintenance Settings',
            'notifications' => 'SMS Reminders',
        ];

        $groupIcons = [
            'general'       => 'bx-cog',
            'email'         => 'bx-envelope',
            'security'      => 'bx-shield',
            'microfinance'  => 'bx-money',
            'backup'        => 'bx-data',
            'maintenance'   => 'bx-wrench',
            'notifications' => 'bx-bell',
        ];

        $timezones = [
            'Africa/Dar_es_Salaam',
            'Africa/Nairobi',
            'Africa/Kampala',
            'Africa/Kigali',
            'Africa/Bujumbura',
            'UTC',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'Europe/London',
            'Europe/Paris',
            'Europe/Berlin',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Asia/Kolkata',
            'Australia/Sydney',
            'Africa/Cairo',
            'Africa/Lagos',
            'America/Sao_Paulo',
            'Pacific/Auckland'
        ];

        // Ensure defaults exist (safe: updateOrCreate).
        // This also seeds newly-added settings keys on existing installations.
        \App\Models\SystemSetting::initializeDefaults();

        $settings = [];
        foreach ($groups as $groupKey => $groupName) {
            $settings[$groupKey] = \App\Models\SystemSetting::getByGroup($groupKey);
        }

        return view('settings.system', compact('groups', 'groupIcons', 'timezones', 'settings'));
    }

    public function updateSystemSettings(Request $request)
    {
        // Check permissions for editing system configurations
        if (!auth()->user()->can('edit system configurations') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to edit system configurations.');
        }

        $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable|string',
        ]);

        try {
            foreach ($request->settings as $key => $value) {
                $setting = \App\Models\SystemSetting::where('key', $key)->first();

                if ($setting) {
                    // Handle different input types
                    if ($setting->type === 'boolean') {
                        $value = $value === '1' || $value === 'true' || $value === 'on';
                    } elseif ($setting->type === 'integer') {
                        $value = (int) $value;
                    }

                    $setting->update(['value' => $value]);
                }
            }

            // Clear cache
            \App\Models\SystemSetting::clearCache();

            // Apply security settings to configuration
            $this->applySecuritySettings();

            return redirect()->route('settings.system')->with('success', 'System settings updated successfully!');
        } catch (\Exception $e) {
            return redirect()->route('settings.system')->with('error', 'Failed to update settings: ' . $e->getMessage());
        }
    }

    /**
     * Apply security settings to Laravel configuration
     */
    private function applySecuritySettings()
    {
        $securityConfig = \App\Services\SystemSettingService::getSecurityConfig();
        
        // Apply session lifetime
        if (isset($securityConfig['session_lifetime'])) {
            config(['session.lifetime' => $securityConfig['session_lifetime']]);
        }
    }

    public function resetSystemSettings()
    {
        // Check permissions for managing system configurations
        if (!auth()->user()->can('manage system configurations') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to reset system configurations.');
        }

        try {
            \App\Models\SystemSetting::truncate();
            \App\Models\SystemSetting::initializeDefaults();

            return redirect()->route('settings.system')->with('success', 'System settings reset to defaults successfully!');
        } catch (\Exception $e) {
            return redirect()->route('settings.system')->with('error', 'Failed to reset settings: ' . $e->getMessage());
        }
    }



    /**
     * Test email configuration
     */
    public function testEmailConfig()
    {
        try {
            $result = \App\Services\SystemSettingService::testEmailConfig();

            if ($result['success']) {
                return response()->json(['success' => true, 'message' => $result['message']]);
            } else {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Email test failed: ' . $e->getMessage()], 500);
        }
    }


    public function backupSettings()
    {
        // Check permissions for backup settings
        if (!auth()->user()->can('view backup settings') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to view backup settings.');
        }

        $backupService = new BackupService();
        $stats = $backupService->getBackupStats();

        return view('settings.backup', compact('stats'));
    }

    /**
     * DataTables AJAX: Backup history (server-side).
     */
    public function backupHistoryData(Request $request)
    {
        if (!$request->ajax()) {
            return response()->json([], 400);
        }
        $query = Backup::forCompany()->with('creator')->orderBy('created_at', 'desc');
        return DataTables::eloquent($query)
            ->addColumn('name_cell', function ($backup) {
                $html = '<div class="fw-bold">' . e($backup->name) . '</div>';
                if ($backup->description) {
                    $html .= '<small class="text-muted">' . e($backup->description) . '</small>';
                }
                return $html;
            })
            ->addColumn('type_badge', function ($backup) {
                if ($backup->type === 'database') {
                    return '<span class="badge bg-primary">Database</span>';
                }
                if ($backup->type === 'files') {
                    return '<span class="badge bg-success">Files</span>';
                }
                return '<span class="badge bg-info">Full</span>';
            })
            ->addColumn('formatted_size', function ($backup) {
                return $backup->formatted_size;
            })
            ->addColumn('status_badge', function ($backup) {
                if ($backup->status === 'completed') {
                    return '<span class="badge bg-success">Completed</span>';
                }
                if ($backup->status === 'failed') {
                    return '<span class="badge bg-danger">Failed</span>';
                }
                return '<span class="badge bg-warning">In Progress</span>';
            })
            ->addColumn('creator_name', function ($backup) {
                return $backup->creator ? e($backup->creator->name) : 'Unknown';
            })
            ->addColumn('created_at_fmt', function ($backup) {
                return $backup->created_at->format('Y-m-d H:i:s');
            })
            ->addColumn('actions', function ($backup) {
                if ($backup->status !== 'completed') {
                    return '<span class="text-muted">No actions available</span>';
                }
                $downloadUrl = route('settings.backup.download', $backup->hash_id);
                $actions = '<div class="btn-group" role="group">';
                $actions .= '<a href="' . e($downloadUrl) . '" class="btn btn-sm btn-info" title="Download"><i class="bx bx-download"></i></a>';
                $actions .= '<button type="button" class="btn btn-sm btn-warning backup-restore-btn" data-backup-id="' . (int) $backup->id . '" data-name="' . e($backup->name) . '" title="Restore"><i class="bx bx-reset"></i></button>';
                $actions .= '<button type="button" class="btn btn-sm btn-danger backup-delete-btn" data-hash-id="' . e($backup->hash_id) . '" data-name="' . e($backup->name) . '" title="Delete"><i class="bx bx-trash"></i></button>';
                $actions .= '</div>';
                return $actions;
            })
            ->rawColumns(['name_cell', 'type_badge', 'status_badge', 'actions'])
            ->make(true);
    }

    public function createBackup(Request $request)
    {
        // Check permissions for creating backups
        if (!auth()->user()->can('create backup') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to create backups.');
        }

        $request->validate([
            'type' => 'required|in:database,full',
            'description' => 'nullable|string|max:500',
        ]);

        $jobLog = JobLog::create([
            'job_name' => 'BackupJob',
            'status' => 'running',
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'started_at' => now(),
            'result_details' => ['type' => $request->type],
        ]);

        $this->ensureQueueWorkerRunning();

        BackupJob::dispatch(
            $request->type,
            auth()->id(),
            current_company_id(),
            $request->description,
            $jobLog->id
        );

        Log::info('Backup job dispatched', ['job_log_id' => $jobLog->id, 'user_id' => auth()->id()]);

        return redirect()->route('settings.backup')
            ->with('success', ucfirst($request->type) . ' backup has been queued and will run in the background.')
            ->with('job_log_id', $jobLog->id);
    }

    /**
     * Backup job status (JSON for polling), like loans reminder-sms.
     */
    public function backupJobStatus($jobLogId)
    {
        $jobLog = JobLog::findOrFail($jobLogId);
        if ($jobLog->job_name !== 'BackupJob') {
            return response()->json(['error' => 'Invalid job'], 404);
        }
        return response()->json([
            'status' => $jobLog->status,
            'processed' => $jobLog->processed,
            'successful' => $jobLog->successful,
            'failed' => $jobLog->failed,
            'summary' => $jobLog->summary,
            'error_message' => $jobLog->error_message,
            'started_at' => $jobLog->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $jobLog->completed_at?->format('Y-m-d H:i:s'),
            'duration' => $jobLog->duration_seconds ? (floor($jobLog->duration_seconds / 60) . 'm ' . ($jobLog->duration_seconds % 60) . 's') : null,
        ]);
    }

    /**
     * DataTables AJAX: Backup job logs (like reminder SMS jobs table).
     */
    public function backupJobsData(Request $request)
    {
        if (!$request->ajax()) {
            return response()->json([], 400);
        }
        $query = JobLog::where('job_name', 'BackupJob')->orderBy('id', 'desc');
        return DataTables::eloquent($query)
            ->addColumn('status_badge', function ($log) {
                $displayStatus = $log->status;
                if ($log->status === 'running') {
                    $details = is_array($log->result_details ?? null) ? $log->result_details : [];
                    $backup = null;
                    if (!empty($details['backup_id'])) {
                        $backup = Backup::forCompany()->find($details['backup_id']);
                    }
                    if (!$backup && !empty($details['type'])) {
                        $started = $log->started_at ?? $log->created_at;
                        $backup = Backup::forCompany()
                            ->where('type', $details['type'])
                            ->where('status', 'completed')
                            ->where('created_at', '>=', $started->copy()->subMinutes(2))
                            ->where('created_at', '<=', $started->copy()->addMinutes(30))
                            ->orderByDesc('id')
                            ->first();
                    }
                    if ($backup && $backup->status === 'completed') {
                        $displayStatus = 'completed';
                    }
                }
                if ($displayStatus === 'running') {
                    return '<span class="badge bg-primary">Running</span>';
                }
                if ($displayStatus === 'completed') {
                    return '<span class="badge bg-success">Completed</span>';
                }
                if ($displayStatus === 'failed') {
                    return '<span class="badge bg-danger">Failed</span>';
                }
                return '<span class="badge bg-secondary">' . e($displayStatus) . '</span>';
            })
            ->addColumn('type_badge', function ($log) {
                $details = $log->result_details;
                $type = is_array($details) && isset($details['type']) ? $details['type'] : '—';
                return '<span class="badge bg-info">' . e(ucfirst($type)) . '</span>';
            })
            ->addColumn('started_at_fmt', function ($log) {
                return $log->started_at ? $log->started_at->format('d/m/Y H:i') : '—';
            })
            ->addColumn('duration_fmt', function ($log) {
                if (!$log->duration_seconds) {
                    return 'N/A';
                }
                $m = floor($log->duration_seconds / 60);
                $s = $log->duration_seconds % 60;
                return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
            })
            ->rawColumns(['status_badge', 'type_badge'])
            ->make(true);
    }

    /**
     * DataTables AJAX: SMS repayment reminder logs for System Settings.
     */
    public function smsReminderLogsData(Request $request)
    {
        if (!$request->ajax()) {
            return response()->json([], 400);
        }

        if (!auth()->user()->can('view system configurations')
            && !auth()->user()->can('manage system settings')
            && !auth()->user()->hasRole('admin')) {
            abort(403);
        }

        $companyId = current_company_id();

        $query = SmsLog::query()
            ->with('customer')
            ->where('sms_type', SmsLog::TYPE_REPAYMENT_REMINDER)
            ->whereHas('customer', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->orderByDesc('sent_at');

        return DataTables::eloquent($query)
            ->addColumn('customer_name', function (SmsLog $log) {
                return e($log->customer?->name ?? '—');
            })
            ->addColumn('loan_no', function (SmsLog $log) {
                return e($log->reminderMeta()['loan_no'] ?? '—');
            })
            ->addColumn('amount_due', function (SmsLog $log) {
                $amount = $log->reminderMeta()['amount_due'] ?? null;
                return $amount !== null ? number_format((float) str_replace(',', '', (string) $amount), 2) : '—';
            })
            ->addColumn('reminder_type', function (SmsLog $log) {
                return e($log->reminderMeta()['reminder_type'] ?? '—');
            })
            ->addColumn('due_date_fmt', function (SmsLog $log) {
                $dueDate = $log->reminderMeta()['due_date'] ?? null;
                if (!$dueDate) {
                    return '—';
                }
                try {
                    return \Carbon\Carbon::parse($dueDate)->format('d/m/Y');
                } catch (\Throwable) {
                    return e((string) $dueDate);
                }
            })
            ->addColumn('sent_at_fmt', function (SmsLog $log) {
                return $log->sent_at ? $log->sent_at->format('d/m/Y H:i') : '—';
            })
            ->addColumn('status_badge', function (SmsLog $log) {
                $status = $log->deliveryStatus();
                if ($status === 'sent') {
                    return '<span class="badge bg-success">Sent</span>';
                }
                if ($status === 'failed') {
                    return '<span class="badge bg-danger">Failed</span>';
                }
                if ($status === 'skipped') {
                    return '<span class="badge bg-secondary">Skipped</span>';
                }
                return '<span class="badge bg-light text-dark">Unknown</span>';
            })
            ->addColumn('source_label', function () {
                return '<span class="badge bg-info">System</span>';
            })
            ->filterColumn('customer_name', function ($query, $keyword) {
                $query->whereHas('customer', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->filterColumn('loan_no', function ($query, $keyword) {
                $query->where('response', 'like', '%"loan_no":"' . addcslashes($keyword, '"\\') . '%');
            })
            ->rawColumns(['status_badge', 'source_label'])
            ->make(true);
    }

    /**
     * Ensure queue worker is running in the background (same pattern as LoanController reminder SMS).
     */
    private function ensureQueueWorkerRunning(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }
        if (!$this->isQueueWorkerRunning()) {
            $this->startQueueWorker();
        }
    }

    private function isQueueWorkerRunning(): bool
    {
        $command = "ps aux | grep '[a]rtisan queue:work' | grep -v grep";
        exec($command, $output, $returnCode);
        if (config('queue.default') === 'database') {
            $pendingJobs = DB::table('jobs')->count();
            if ($pendingJobs > 0 && empty($output)) {
                return false;
            }
        }
        return !empty($output) && $returnCode === 0;
    }

    private function startQueueWorker(): void
    {
        $artisanPath = base_path('artisan');
        $logPath = storage_path('logs/queue-worker.log');
        $pidFile = storage_path('logs/queue-worker.pid');

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid) {
                exec("ps -p " . escapeshellarg($pid) . " > /dev/null 2>&1", $output, $returnCode);
                if ($returnCode === 0) {
                    Log::info('Queue worker already running', ['pid' => $pid]);
                    return;
                }
            }
        }

        $command = sprintf(
            'cd %s && nohup php %s queue:work --tries=3 --timeout=3600 --max-time=3600 >> %s 2>&1 & echo $! > %s',
            escapeshellarg(base_path()),
            escapeshellarg($artisanPath),
            escapeshellarg($logPath),
            escapeshellarg($pidFile)
        );
        exec($command);
        usleep(1000000);
        Log::info('Queue worker started for backup', ['user_id' => auth()->id(), 'pid_file' => $pidFile]);
    }

    public function restoreBackup(Request $request)
    {
        // Check permissions for restoring backups
        if (!auth()->user()->can('restore backup') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to restore backups.');
        }

        $request->validate([
            'backup_id' => 'required|exists:backups,id',
        ]);

        try {
            $backup = Backup::forCompany()->findOrFail($request->backup_id);
            $backupService = new BackupService();

            $backupService->restoreBackup($backup);

            return redirect()->route('settings.backup')->with('success', 'Backup restored successfully!');

        } catch (\Exception $e) {
            return redirect()->route('settings.backup')->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

    public function importSqlDatabase(Request $request)
    {
        // Prefer the dedicated Import Database page (background job, zip/gz, status polling).
        return redirect()->route('settings.import-database');
    }

    public function importDatabaseSettings()
    {
        if (! $this->canManageDatabaseImport()) {
            abort(403, 'You do not have permission to import databases.');
        }

        $imports = DatabaseImport::forCompany()->orderByDesc('created_at')->limit(20)->get();
        $activeImport = DatabaseImport::forCompany()
            ->whereIn('status', [DatabaseImport::STATUS_QUEUED, DatabaseImport::STATUS_PROCESSING])
            ->orderByDesc('id')
            ->first();

        return view('settings.import-database', compact('imports', 'activeImport'));
    }

    public function importDatabase(Request $request)
    {
        if (! $this->canManageDatabaseImport()) {
            abort(403, 'You do not have permission to import databases.');
        }

        $maxKilobytes = 1024 * 1024; // 1024 MB

        $request->validate([
            'database_file' => 'required|file|max:'.$maxKilobytes,
            'mode' => 'required|in:backup,replace',
            'confirm' => 'accepted',
        ], [
            'database_file.max' => 'The database file may not be greater than 1024 MB.',
            'confirm.accepted' => 'You must confirm that you understand this will overwrite the current database.',
        ]);

        $file = $request->file('database_file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['sql', 'zip', 'gz'], true)) {
            return redirect()
                ->route('settings.import-database')
                ->with('error', 'Only .sql, .sql.gz, or .zip files are allowed.');
        }

        $busy = DatabaseImport::forCompany()
            ->whereIn('status', [DatabaseImport::STATUS_QUEUED, DatabaseImport::STATUS_PROCESSING])
            ->exists();

        if ($busy) {
            return redirect()
                ->route('settings.import-database')
                ->with('error', 'Another database import is already in progress. Please wait for it to finish.');
        }

        try {
            $directory = 'imports/'.date('Y/m');
            $storedName = 'import_'.now()->format('Ymd_His').'_'.Str::random(8).'.'.$extension;
            $storedPath = $file->storeAs($directory, $storedName);

            $import = DatabaseImport::create([
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $file->getSize() ?: 0,
                'mode' => $request->mode,
                'status' => DatabaseImport::STATUS_QUEUED,
                'message' => 'Queued for import.',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]);
            $import->syncCacheStatus();

            // Run via background artisan (does not require queue:work).
            $this->startDatabaseImportProcess((int) $import->id);

            return redirect()
                ->route('settings.import-database')
                ->with('success', 'Database import started in the background. Keep this page open to watch status.');
        } catch (\Exception $e) {
            return redirect()
                ->route('settings.import-database')
                ->with('error', 'Failed to queue import: '.$e->getMessage());
        }
    }

    public function processQueuedDatabaseImport($id)
    {
        if (! $this->canManageDatabaseImport()) {
            abort(403, 'You do not have permission to import databases.');
        }

        $import = DatabaseImport::forCompany()->findOrFail($id);

        if (! in_array($import->status, [DatabaseImport::STATUS_QUEUED, DatabaseImport::STATUS_FAILED], true)) {
            return redirect()
                ->route('settings.import-database')
                ->with('error', 'Only queued or failed imports can be started.');
        }

        $import->update([
            'status' => DatabaseImport::STATUS_QUEUED,
            'message' => 'Restarting import process…',
            'finished_at' => null,
        ]);
        $import->syncCacheStatus();

        $this->startDatabaseImportProcess((int) $import->id);

        return redirect()
            ->route('settings.import-database')
            ->with('success', 'Import process started. Status will update shortly.');
    }

    /**
     * Spawn `php artisan database-import:process {id}` in the background (no queue worker needed).
     */
    protected function startDatabaseImportProcess(int $importId): void
    {
        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $logFile = storage_path('logs/database-import-'.$importId.'.log');

        $cmd = sprintf(
            'nohup %s %s database-import:process %d >> %s 2>&1 & echo $!',
            escapeshellarg($php),
            escapeshellarg($artisan),
            $importId,
            escapeshellarg($logFile)
        );

        $pid = trim((string) shell_exec($cmd));

        Log::info('Database import background process started', [
            'import_id' => $importId,
            'pid' => $pid !== '' ? $pid : null,
            'log' => $logFile,
        ]);
    }

    public function importDatabaseStatus($id)
    {
        if (! $this->canManageDatabaseImport()) {
            abort(403);
        }

        $cached = DatabaseImport::cachedStatus((int) $id);
        $import = DatabaseImport::forCompany()->find($id);

        if ($import) {
            return response()->json([
                'id' => $import->id,
                'status' => $import->status,
                'message' => $import->message,
                'mode' => $import->mode,
                'original_filename' => $import->original_filename,
                'started_at' => optional($import->started_at)?->toDateTimeString(),
                'finished_at' => optional($import->finished_at)?->toDateTimeString(),
            ]);
        }

        if ($cached) {
            return response()->json($cached);
        }

        return response()->json(['message' => 'Import not found.'], 404);
    }

    protected function canManageDatabaseImport(): bool
    {
        $user = auth()->user();

        return $user
            && (
                $user->can('restore backup')
                || $user->can('manage system settings')
                || $user->hasRole(['admin', 'super-admin'])
            );
    }

    public function downloadBackup($hash_id)
    {
        // Check permissions for downloading backups
        if (!auth()->user()->can('view backup settings') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to download backups.');
        }

        // Decode hash ID to get backup ID
        $id = Hashids::decode($hash_id);
        if (empty($id)) {
            abort(404, 'Backup not found.');
        }

        $backup = Backup::forCompany()->find($id[0]);
        if (!$backup) {
            abort(404, 'Backup not found.');
        }

        $fullPath = storage_path('app/' . $backup->file_path);
        if (!file_exists($fullPath)) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($fullPath, $backup->filename);
    }

    public function deleteBackup($hash_id)
    {
        // Check permissions for deleting backups
        if (!auth()->user()->can('delete backup') && 
            !auth()->user()->can('manage system settings') && 
            !auth()->user()->hasRole('admin')) {
            abort(403, 'You do not have permission to delete backups.');
        }

        // Decode hash ID to get backup ID
        $id = Hashids::decode($hash_id);
        if (empty($id)) {
            abort(404, 'Backup not found.');
        }

        $backup = Backup::forCompany()->find($id[0]);
        if (!$backup) {
            abort(404, 'Backup not found.');
        }

        try {
            $backup->deleteFile();
            $backup->delete();

            return redirect()->route('settings.backup')->with('success', 'Backup deleted successfully!');

        } catch (\Exception $e) {
            return redirect()->route('settings.backup')->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function cleanOldBackups(Request $request)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        try {
            $backupService = new BackupService();
            $deletedCount = $backupService->cleanOldBackups($request->days);

            return redirect()->route('settings.backup')->with('success', "{$deletedCount} old backups cleaned successfully!");

        } catch (\Exception $e) {
            return redirect()->route('settings.backup')->with('error', 'Clean failed: ' . $e->getMessage());
        }
    }


    /**
     * AI Assistant Settings
     */
    public function aiAssistantSettings()
    {
        return view('settings.ai-assistant');
    }

    /**
     * Handle AI chat requests
     */
    public function aiChat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        try {
            $aiService = new AiAssistantService();
            $response = $aiService->processMessage($request->message);

            return response()->json([
                'success' => true,
                'response' => $response
            ]);

        } catch (\Exception $e) {
            \Log::error('AI Chat Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'AI processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Penalty Settings
     */
    public function penaltySettings()
    {
        return view('settings.penalty');
    }

    /**
     * Update Penalty Settings
     */
    public function updatePenaltySettings(Request $request)
    {
        $request->validate([
            'late_payment_penalty' => 'required|numeric|min:0|max:100',
            'penalty_grace_period' => 'required|integer|min:0|max:365',
            'penalty_calculation_method' => 'required|in:percentage,fixed',
            'penalty_currency' => 'required|string|max:10',
        ]);

        try {
            // Update penalty settings logic here
            // This would typically save to a settings table or config file

            return redirect()->route('settings.penalty')->with('success', 'Penalty settings updated successfully!');
        } catch (\Exception $e) {
            return redirect()->route('settings.penalty')->with('error', 'Failed to update penalty settings: ' . $e->getMessage());
        }
    }

    /**
     * Fees Settings
     */
    public function feesSettings()
    {
        return view('settings.fees');
    }

    /**
     * Update Fees Settings
     */
    public function updateFeesSettings(Request $request)
    {
        $request->validate([
            'service_fee_percentage' => 'required|numeric|min:0|max:100',
            'transaction_fee' => 'required|numeric|min:0',
            'minimum_fee' => 'required|numeric|min:0',
            'maximum_fee' => 'required|numeric|min:0',
            'fee_currency' => 'required|string|max:10',
        ]);

        try {
            // Update fees settings logic here
            // This would typically save to a settings table or config file

            return redirect()->route('settings.fees')->with('success', 'Fees settings updated successfully!');
        } catch (\Exception $e) {
            return redirect()->route('settings.fees')->with('error', 'Failed to update fees settings: ' . $e->getMessage());
        }
    }

    /**
     * SMS Settings
     */
    public function smsSettings()
    {
        $smsEvents = [
            'otp_verification'      => 'User login / OTP verification',
            'loan_disbursement'     => 'On loan disbursement',
            'loan_repayment'        => 'On loan repayment',
            'loan_arrears_reminder' => 'Loan arrears / reminder messages',
            'customer_notifications'=> 'Customer automatic notifications',
            'group_notifications'   => 'Group automatic notifications',
            'cash_collateral'       => 'Cash collateral notifications',
            'mature_interest'       => 'Mature interest collection notifications',
            'loan_penalty'          => 'Loan penalty / overdue fine notifications',
        ];

        // Available template variables per event (shown as hints in the UI)
        $eventVariables = [
            'otp_verification'      => ['{code}'],
            'loan_disbursement'     => ['{customer_name}', '{amount}', '{loan_date}', '{repayment_start_date}', '{payment_amount}', '{cycle}', '{company_name}', '{company_phone}', '{loan_no}'],
            'loan_repayment'        => ['{customer_name}', '{amount}', '{payment_date}', '{loan_no}', '{next_schedule_amount}', '{outstanding_amount}', '{company_name}', '{company_phone}'],
            'loan_arrears_reminder' => ['{customer_name}', '{amount}', '{days_overdue}', '{loan_no}', '{due_date}', '{reminder_type}', '{company_name}', '{company_phone}'],
            'customer_notifications'=> ['{customer_name}', '{company_name}'],
            'group_notifications'   => ['{customer_name}', '{amount_paid}', '{remaining_amount}', '{company_name}'],
            'cash_collateral'       => ['{amount}', '{action}', '{company_name}'],
            'mature_interest'       => ['{customer_name}', '{loan_no}', '{amount}', '{company_name}'],
            'loan_penalty'          => ['{customer_name}', '{amount}', '{days_overdue}', '{loan_no}', '{due_date}', '{company_name}', '{company_phone}'],
        ];

        // System default message shown as placeholder when no custom template is set
        $defaultMessages = [
            'otp_verification'      => 'OTP Code is {code}',
            'loan_disbursement'     => 'Umepokea mkopo wa Tsh {amount} tarehe {loan_date}, Marejesho yako yataanza {repayment_start_date} na utakuwa unalipa Tsh {payment_amount} {cycle}. Asante. Ujumbe umetoka {company_name}',
            'loan_repayment'        => 'Habari! {customer_name}, Tumepokea marejesho ya Tsh {amount} tarehe {payment_date} kutoka kwenye mkopo namba {loan_no}. Asante. Ujumbe umetoka {company_name}',
            'loan_arrears_reminder' => 'Habari! {customer_name}, Mkopo wako una deni la Tsh {amount} na umekwisha siku {days_overdue}. Tafadhali fanya malipo yako mapema. Asante. Ujumbe umetoka {company_name}',
            'customer_notifications'=> '(No default — message is composed manually)',
            'group_notifications'   => 'Habari! {customer_name}, umelipa rejesho kiasi cha Tsh {amount_paid}. Salio: Tsh {remaining_amount}. {company_name}',
            'cash_collateral'       => 'Cash {action} processed successfully. Amount: TSHS{amount}',
            'mature_interest'       => 'Habari {customer_name}. Mkopo namba {loan_no} una deni la faini ya TZS {amount} kwa kuchelewa kulipa. Tafadhali lipa haraka ili uepuke faini zaidi. Asante.',
            'loan_penalty'          => 'Habari ndugu mteja {customer_name}. Adhabu ya TZS {amount} imeongezwa kwenye mkopo namba {loan_no} kwa kuchelewa kulipa ({days_overdue}). Tafadhali lipa haraka ili uepuke adhabu zaidi. Asante, kwa mawasiliano piga {company_phone}.',
        ];

        $enabledEvents = [];
        $customTemplates = [];
        foreach ($smsEvents as $key => $label) {
            $enabledEvents[$key] = filter_var(
                config("services.sms.events.$key", true),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) !== false;
            $customTemplates[$key] = config("services.sms.templates.$key", '');
        }

        $customTemplates['loan_disbursement_company'] = config('services.sms.templates.loan_disbursement_company', '');
        $customTemplates['loan_repayment_company'] = config('services.sms.templates.loan_repayment_company', '');

        $loanDisbursementRecipients = config('services.sms.loan_disbursement_recipients', 'customer');
        if (!in_array($loanDisbursementRecipients, ['customer', 'customer_and_company'], true)) {
            $loanDisbursementRecipients = 'customer';
        }

        $loanRepaymentRecipients = config('services.sms.loan_repayment_recipients', 'customer');
        if (!in_array($loanRepaymentRecipients, ['customer', 'customer_and_company'], true)) {
            $loanRepaymentRecipients = 'customer';
        }

        return view('settings.sms', compact(
            'smsEvents',
            'enabledEvents',
            'eventVariables',
            'defaultMessages',
            'customTemplates',
            'loanDisbursementRecipients',
            'loanRepaymentRecipients'
        ));
    }

    /**
     * Update SMS Settings
     */
    public function updateSmsSettings(Request $request)
    {
        $request->validate([
            'sms_url'          => 'required|url',
            'sms_senderid'     => 'required|string|max:255',
            'sms_key'          => 'required|string|max:255',
            'sms_token'        => 'required|string|max:255',
            'test_phone'       => 'nullable|string|max:20',
            'sms_events'       => 'nullable|array',
            'sms_events.*'     => 'string',
            'sms_templates'    => 'nullable|array',
            'sms_templates.*'  => 'nullable|string|max:500',
            'loan_disbursement_recipients' => 'nullable|in:customer,customer_and_company',
            'loan_repayment_recipients' => 'nullable|in:customer,customer_and_company',
        ]);

        try {
            // Update .env file with SMS settings
            $envKeys = [
                'BEEM_SMS_URL' => $request->sms_url,
                'BEEM_SENDER_ID' => $request->sms_senderid,
                'BEEM_API_KEY' => $request->sms_key,
                'BEEM_SECRET_KEY' => $request->sms_token,
            ];

            // Also set fallback SMS_* keys
            $envKeys['SMS_URL'] = $request->sms_url;
            $envKeys['SMS_SENDERID'] = $request->sms_senderid;
            $envKeys['SMS_KEY'] = $request->sms_key;
            $envKeys['SMS_TOKEN'] = $request->sms_token;

            // SMS event toggles
            $smsEvents = [
                'otp_verification',
                'loan_disbursement',
                'loan_repayment',
                'loan_arrears_reminder',
                'customer_notifications',
                'group_notifications',
                'cash_collateral',
                'mature_interest',
                'loan_penalty',
            ];

            $selectedEvents = $request->input('sms_events', []);

            foreach ($smsEvents as $eventKey) {
                $envKey = 'SMS_EVENT_' . strtoupper($eventKey);
                $envKeys[$envKey] = in_array($eventKey, $selectedEvents, true) ? 'true' : 'false';
            }

            // Save custom message templates (empty string clears the template → fallback to default)
            $submittedTemplates = $request->input('sms_templates', []);
            foreach ($smsEvents as $eventKey) {
                $templateEnvKey = 'SMS_TEMPLATE_' . strtoupper($eventKey);
                $envKeys[$templateEnvKey] = trim($submittedTemplates[$eventKey] ?? '');
            }

            // Company copy templates (use same event toggles as loan_disbursement / loan_repayment)
            $companyTemplateKeys = ['loan_disbursement_company', 'loan_repayment_company'];
            foreach ($companyTemplateKeys as $eventKey) {
                $envKeys['SMS_TEMPLATE_' . strtoupper($eventKey)] = trim($submittedTemplates[$eventKey] ?? '');
            }

            $disbursementRecipients = $request->input('loan_disbursement_recipients', 'customer');
            if (!in_array($disbursementRecipients, ['customer', 'customer_and_company'], true)) {
                $disbursementRecipients = 'customer';
            }
            $envKeys['SMS_LOAN_DISBURSEMENT_RECIPIENTS'] = $disbursementRecipients;

            $repaymentRecipients = $request->input('loan_repayment_recipients', 'customer');
            if (!in_array($repaymentRecipients, ['customer', 'customer_and_company'], true)) {
                $repaymentRecipients = 'customer';
            }
            $envKeys['SMS_LOAN_REPAYMENT_RECIPIENTS'] = $repaymentRecipients;

            foreach ($envKeys as $key => $value) {
                if (!update_env_file($key, $value)) {
                    throw new \Exception("Failed to update {$key} in .env file");
                }
            }

            // Clear config cache to reload .env values
            \Artisan::call('config:clear');

            // If test phone is provided, send test SMS
            if ($request->filled('test_phone')) {
                // Temporarily update config to use new values for testing
                config([
                    'services.sms.senderid' => $request->sms_senderid,
                    'services.sms.token' => $request->sms_token,
                    'services.sms.key' => $request->sms_key,
                    'services.sms.url' => $request->sms_url,
                ]);

                $testResult = \App\Helpers\SmsHelper::test($request->test_phone);
                
                if ($testResult['success'] ?? false) {
                    return redirect()->route('settings.sms')->with('success', 'SMS settings updated and test SMS sent successfully!');
                } else {
                    return redirect()->route('settings.sms')
                        ->with('success', 'SMS settings updated successfully!')
                        ->with('warning', 'Test SMS failed: ' . ($testResult['error'] ?? 'Unknown error'));
                }
            }

            return redirect()->route('settings.sms')->with('success', 'SMS settings updated successfully! Please note that you may need to restart your application server for changes to take full effect.');
        } catch (\Exception $e) {
            \Log::error('SMS Settings Update Error: ' . $e->getMessage());
            return redirect()->route('settings.sms')->with('error', 'Failed to update SMS settings: ' . $e->getMessage());
        }
    }

    /**
     * Test SMS Configuration
     */
    public function testSmsSettings(Request $request)
    {
        $request->validate([
            'test_phone' => 'required|string|max:20',
            'sms_url' => 'nullable|url',
            'sms_senderid' => 'nullable|string|max:255',
            'sms_key' => 'nullable|string|max:255',
            'sms_token' => 'nullable|string|max:255',
        ]);

        try {
            // If form values are provided, use them temporarily for testing
            if ($request->filled('sms_url') && $request->filled('sms_senderid') && 
                $request->filled('sms_key') && $request->filled('sms_token')) {
                // Temporarily update config to use form values
                config([
                    'services.sms.senderid' => $request->sms_senderid,
                    'services.sms.token' => $request->sms_token,
                    'services.sms.key' => $request->sms_key,
                    'services.sms.url' => $request->sms_url,
                ]);
            }

            $result = \App\Helpers\SmsHelper::test($request->test_phone);
            
            if ($result['success'] ?? false) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test SMS sent successfully! Please check the recipient phone.'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? 'Failed to send test SMS'
                ], 400);
            }
        } catch (\Exception $e) {
            \Log::error('SMS Test Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function dcbSettings()
    {
        $recentTransactions = \App\Models\DcbTransaction::query()
            ->when(current_company_id(), fn ($q) => $q->where('company_id', current_company_id()))
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $callbackUrl = url('/dcb/callback');
        $bankAccounts = \App\Models\BankAccount::forUserBranches()->orderBy('name')->get();

        return view('settings.dcb', compact('recentTransactions', 'callbackUrl', 'bankAccounts'));
    }

    public function updateDcbSettings(Request $request)
    {
        $request->validate([
            'dcb_enabled' => 'nullable|boolean',
            'dcb_base_url' => 'required|url',
            'dcb_business_id' => 'required|string|max:255',
            'dcb_api_key' => 'required|string|max:255',
            'dcb_api_secret' => 'required|string|max:255',
            'dcb_sender_name' => 'required|string|max:120',
            'dcb_callback_secret' => 'nullable|string|max:255',
            'dcb_default_institution_code' => 'nullable|string|max:64',
            'dcb_settlement_bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        try {
            $envKeys = [
                'DCB_ENABLED' => $request->boolean('dcb_enabled') ? 'true' : 'false',
                'DCB_BASE_URL' => $request->dcb_base_url,
                'DCB_BUSINESS_ID' => $request->dcb_business_id,
                'DCB_API_KEY' => $request->dcb_api_key,
                'DCB_API_SECRET' => $request->dcb_api_secret,
                'DCB_SENDER_NAME' => $request->dcb_sender_name,
                'DCB_CALLBACK_SECRET' => $request->dcb_callback_secret ?? '',
                'DCB_DEFAULT_INSTITUTION_CODE' => $request->dcb_default_institution_code ?? '',
                'DCB_SETTLEMENT_BANK_ACCOUNT_ID' => $request->dcb_settlement_bank_account_id ?? '',
            ];

            foreach ($envKeys as $key => $value) {
                if (!update_env_file($key, $value)) {
                    throw new \Exception("Failed to update {$key} in .env file");
                }
            }

            \Artisan::call('config:clear');
            app(\App\Services\DcbGatewayService::class)->clearToken();

            return redirect()->route('settings.dcb')->with('success', 'DCB gateway settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('DCB Settings Update Error: ' . $e->getMessage());

            return redirect()->route('settings.dcb')->with('error', 'Failed to update DCB settings: ' . $e->getMessage());
        }
    }

    public function testDcbSettings(Request $request)
    {
        try {
            if ($request->filled('dcb_base_url')) {
                config([
                    'services.dcb.base_url' => $request->dcb_base_url,
                    'services.dcb.business_id' => $request->dcb_business_id,
                    'services.dcb.api_key' => $request->dcb_api_key,
                    'services.dcb.api_secret' => $request->dcb_api_secret,
                ]);
            }

            app(\App\Services\DcbGatewayService::class)->clearToken();
            $result = app(\App\Services\DcbGatewayService::class)->testConnection();

            return response()->json([
                'success' => (bool) ($result['success'] ?? false),
                'message' => $result['message'] ?? 'Connection test completed.',
                'institutions_count' => $result['institutions_count'] ?? null,
            ], ($result['success'] ?? false) ? 200 : 400);
        } catch (\Exception $e) {
            Log::error('DCB Test Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Payment Voucher Approval Settings
     */
    public function paymentVoucherApprovalSettings()
    {
        $user = Auth::user();
        
        // Load roles and users for dropdowns
        $roles = \Spatie\Permission\Models\Role::all();
        $users = \App\Models\User::where('company_id', $user->company_id)->excludeSuperAdmin()->get();
        
        // Load existing approval settings
        $settings = \App\Models\PaymentVoucherApprovalSetting::where('company_id', $user->company_id)->first();
        
        return view('settings.payment-voucher-approval', compact('roles', 'users', 'settings'));
    }

    /**
     * Update Payment Voucher Approval Settings
     */
    public function updatePaymentVoucherApprovalSettings(Request $request)
    {
        $requireAll = $request->has('require_approval_for_all');

        $baseRules = [
            'require_approval_for_all' => 'boolean',
        ];

        $approvalRules = [
            'approval_levels' => 'required|integer|min:1|max:5',
            'level1_approval_type' => 'required|in:role,user',
            'level1_approvers' => 'required|array|min:1',
            'level2_approval_type' => 'nullable|in:role,user',
            'level2_approvers' => 'nullable|array',
            'level3_approval_type' => 'nullable|in:role,user',
            'level3_approvers' => 'nullable|array',
            'level4_approval_type' => 'nullable|in:role,user',
            'level4_approvers' => 'nullable|array',
            'level5_approval_type' => 'nullable|in:role,user',
            'level5_approvers' => 'nullable|array',
        ];

        $rules = $requireAll ? array_merge($baseRules, $approvalRules) : $baseRules;
        $request->validate($rules);

        try {
            $user = Auth::user();
            $companyId = $user->company_id;

            // Find or create approval settings for the company
            $settings = \App\Models\PaymentVoucherApprovalSetting::firstOrCreate(
                ['company_id' => $companyId],
                [
                    'approval_levels' => 1,
                    'require_approval_for_all' => false,
                ]
            );

            // Update settings
            $updateData = [
                'require_approval_for_all' => $requireAll,
            ];
            if ($requireAll) {
                // Only approval_levels is strictly needed; keep others as previously configured
                $updateData = array_merge($updateData, [
                    'approval_levels' => $request->approval_levels,
                ]);
            }
            $settings->update($updateData);

            // Update approval assignments
            if ($requireAll) {
                $approvalLevels = (int) $request->approval_levels;
                
                for ($level = 1; $level <= $approvalLevels; $level++) {
                    $approvalType = $request->{"level{$level}_approval_type"};
                    $approvers = $request->{"level{$level}_approvers"} ?? [];

                    if ($approvalType && !empty($approvers)) {
                        // Process approver IDs - extract actual IDs from "user_X" or "role_X" format
                        $processedApprovers = [];
                        foreach ($approvers as $approver) {
                            if (str_starts_with($approver, 'user_')) {
                                $userId = (int) str_replace('user_', '', $approver);
                                $processedApprovers[] = $userId;
                            } elseif (str_starts_with($approver, 'role_')) {
                                $roleName = str_replace('role_', '', $approver);
                                $processedApprovers[] = $roleName;
                            }
                        }

                        $settings->update([
                            "level{$level}_approval_type" => $approvalType,
                            "level{$level}_approvers" => $processedApprovers,
                        ]);
                    }
                }
                
                // Clear unused levels
                for ($level = $approvalLevels + 1; $level <= 5; $level++) {
                    $settings->update([
                        "level{$level}_approval_type" => null,
                        "level{$level}_approvers" => null,
                    ]);
                }
            } else {
                // When approvals are disabled, set levels to 0 and clear approvers only.
                // Do NOT set level1_approval_type (non-nullable) to null.
                $settings->update([
                    'approval_levels' => 0,
                    'level1_approvers' => null,
                    'level2_approvers' => null,
                    'level3_approvers' => null,
                    'level4_approvers' => null,
                    'level5_approvers' => null,
                ]);
            }

            return redirect()->route('settings.payment-voucher-approval')->with('success', 'Payment voucher approval settings updated successfully!');
        } catch (\Exception $e) {
            return redirect()->route('settings.payment-voucher-approval')->with('error', 'Failed to update payment voucher approval settings: ' . $e->getMessage());
        }
    }

    /**
     * Arrears classifications (loan aging buckets) UI
     */
    public function arrearsClassificationsIndex()
    {
        $this->authorizeArrearsClassifications();

        return view('settings.arrears-classifications');
    }

    /**
     * DataTables: arrears classifications
     */
    public function arrearsClassificationsData()
    {
        $this->authorizeArrearsClassifications();

        $query = ArrearsClassification::forCompany()->orderBy('sort_order')->orderBy('days_from');

        return DataTables::of($query)
            ->addColumn('bucket_label', function (ArrearsClassification $c) {
                $to = $c->days_to === null ? '+' : $c->days_to;
                return e(($c->bucket_label ?: ($c->days_from . '-' . $to))) . '<br><small class="text-muted">' . e($c->days_from . ' - ' . $to . ' days') . '</small>';
            })
            ->addColumn('status_badge', function (ArrearsClassification $c) {
                $status = (string) $c->status;
                $badge = 'bg-secondary';
                $statusLower = strtolower($status);
                if (str_contains($statusLower, 'current')) $badge = 'bg-success';
                if (str_contains($statusLower, 'past')) $badge = 'bg-warning text-dark';
                if (str_contains($statusLower, 'especially')) $badge = 'bg-info';
                if (str_contains($statusLower, 'watch')) $badge = 'bg-info';
                if (str_contains($statusLower, 'substandard')) $badge = 'bg-danger';
                if (str_contains($statusLower, 'doubtful')) $badge = 'bg-danger';
                if (str_contains($statusLower, 'loss')) $badge = 'bg-dark';
                return '<span class="badge ' . $badge . '">' . e($status) . '</span>';
            })
            ->addColumn('provision_formatted', function (ArrearsClassification $c) {
                return '<span class="fw-bold">' . number_format((float) $c->provision_percentage, 2) . '%</span>';
            })
            ->addColumn('active_badge', function (ArrearsClassification $c) {
                return $c->is_active
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Inactive</span>';
            })
            ->addColumn('actions', function (ArrearsClassification $c) {
                $row = [
                    'id' => $c->id,
                    'days_from' => $c->days_from,
                    'days_to' => $c->days_to,
                    'bucket_label' => $c->bucket_label,
                    'status' => $c->status,
                    'provision_percentage' => (float) $c->provision_percentage,
                    'sort_order' => $c->sort_order,
                    'is_active' => (bool) $c->is_active,
                    'comments' => $c->comments,
                ];

                return '<div class="d-flex gap-1 justify-content-center">'
                    . '<button type="button" class="btn btn-sm btn-outline-primary btn-edit-classification" data-row=\'' . e(json_encode($row)) . '\'><i class="bx bx-edit"></i></button>'
                    . '<button type="button" class="btn btn-sm btn-outline-danger delete-classification-btn" data-id="' . e($c->id) . '" data-bucket-label="' . e($c->bucket_label) . '"><i class="bx bx-trash"></i></button>'
                    . '</div>';
            })
            ->rawColumns(['bucket_label', 'status_badge', 'provision_formatted', 'active_badge', 'actions'])
            ->make(true);
    }

    public function arrearsClassificationsStore(Request $request)
    {
        $this->authorizeArrearsClassifications();

        $data = $request->validate([
            'days_from' => 'required|integer|min:0',
            'days_to' => 'nullable|integer|min:0',
            'bucket_label' => 'required|string|max:255',
            'status' => 'required|string|max:255',
            'provision_percentage' => 'required|numeric|min:0|max:100',
            'comments' => 'nullable|string',
            'is_active' => 'nullable|in:0,1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $data['company_id'] = current_company_id();
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        ArrearsClassification::create($data);

        return redirect()->route('settings.arrears-classifications.index')->with('success', 'Classification created successfully.');
    }

    public function arrearsClassificationsUpdate(Request $request, ArrearsClassification $arrearsClassification)
    {
        $this->authorizeArrearsClassifications();

        if ((int) $arrearsClassification->company_id !== (int) current_company_id()) {
            abort(403, 'Unauthorized.');
        }

        $data = $request->validate([
            'days_from' => 'required|integer|min:0',
            'days_to' => 'nullable|integer|min:0',
            'bucket_label' => 'required|string|max:255',
            'status' => 'required|string|max:255',
            'provision_percentage' => 'required|numeric|min:0|max:100',
            'comments' => 'nullable|string',
            'is_active' => 'nullable|in:0,1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? $arrearsClassification->is_active);
        $data['sort_order'] = (int) ($data['sort_order'] ?? $arrearsClassification->sort_order);

        $arrearsClassification->update($data);

        return redirect()->route('settings.arrears-classifications.index')->with('success', 'Classification updated successfully.');
    }

    public function arrearsClassificationsDestroy(Request $request, ArrearsClassification $arrearsClassification)
    {
        $this->authorizeArrearsClassifications();

        if ((int) $arrearsClassification->company_id !== (int) current_company_id()) {
            abort(403, 'Unauthorized.');
        }

        $arrearsClassification->delete();

        return response()->json(['success' => true, 'message' => 'Classification deleted.']);
    }

    public function arrearsClassificationsClearAll()
    {
        $this->authorizeArrearsClassifications();

        ArrearsClassification::forCompany()->delete();

        return response()->json(['success' => true, 'message' => 'All classifications cleared.']);
    }

    public function arrearsClassificationsSeedDefaults()
    {
        $this->authorizeArrearsClassifications();

        if (ArrearsClassification::forCompany()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'Classifications already exist. Clear them first if you want to reseed.']);
        }

        $defaults = [
            ['days_from' => 0, 'days_to' => 5, 'bucket_label' => '0-5', 'status' => 'Current', 'provision_percentage' => 1, 'sort_order' => 1],
            ['days_from' => 6, 'days_to' => 30, 'bucket_label' => '6-30', 'status' => 'Especially Mentioned', 'provision_percentage' => 5, 'sort_order' => 2],
            ['days_from' => 31, 'days_to' => 60, 'bucket_label' => '31-60', 'status' => 'Substandard', 'provision_percentage' => 25, 'sort_order' => 3],
            ['days_from' => 61, 'days_to' => 90, 'bucket_label' => '61-90', 'status' => 'Doubtful', 'provision_percentage' => 50, 'sort_order' => 4],
            ['days_from' => 91, 'days_to' => null, 'bucket_label' => '91+', 'status' => 'Loss/NPL', 'provision_percentage' => 100, 'sort_order' => 5],
        ];

        foreach ($defaults as $row) {
            ArrearsClassification::create(array_merge($row, [
                'company_id' => current_company_id(),
                'is_active' => true,
                'comments' => null,
            ]));
        }

        return response()->json(['success' => true, 'message' => 'Default Tanzania classifications created.']);
    }

    /**
     * Job Logs Index
     */
    public function jobLogsIndex()
    {
        // Check permissions
        if (!auth()->user()->can('view logs activity')) {
            abort(403, 'You do not have permission to view job logs.');
        }

        return view('settings.job-logs.index');
    }

    /**
     * Get Job Logs Data for DataTable
     */
    public function jobLogsData(Request $request)
    {
        try {
            // Check permissions
            if (!auth()->user()->can('view logs activity')) {
                return response()->json([
                    'error' => 'You do not have permission to view job logs.'
                ], 403);
            }

            $query = \App\Models\JobLog::query();

            // Apply filters
            if ($request->filled('job_name')) {
                $query->where('job_name', $request->job_name);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('started_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('started_at', '<=', $request->date_to);
            }

            // Calculate summary statistics
            $totalJobs = \App\Models\JobLog::count();
            $completedJobs = \App\Models\JobLog::where('status', 'completed')->count();
            $failedJobs = \App\Models\JobLog::where('status', 'failed')->count();
            $runningJobs = \App\Models\JobLog::where('status', 'running')->count();

            return DataTables::of($query)
                ->addIndexColumn()
                ->addColumn('status_badge', function ($row) {
                    $badges = [
                        'pending' => '<span class="badge bg-warning">Pending</span>',
                        'running' => '<span class="badge bg-info">Running</span>',
                        'completed' => '<span class="badge bg-success">Completed</span>',
                        'failed' => '<span class="badge bg-danger">Failed</span>',
                    ];
                    return $badges[$row->status] ?? '<span class="badge bg-secondary">' . ucfirst($row->status) . '</span>';
                })
                ->addColumn('formatted_amount', function ($row) {
                    return $row->total_amount ? 'TZS ' . number_format($row->total_amount, 2) : '-';
                })
                ->addColumn('formatted_duration', function ($row) {
                    if (!$row->duration_seconds) {
                        return 'N/A';
                    }
                    $minutes = floor($row->duration_seconds / 60);
                    $seconds = $row->duration_seconds % 60;
                    if ($minutes > 0) {
                        return "{$minutes}m {$seconds}s";
                    }
                    return "{$seconds}s";
                })
                ->addColumn('started_at_formatted', function ($row) {
                    return $row->started_at ? $row->started_at->format('Y-m-d H:i:s') : '-';
                })
                ->addColumn('actions', function ($row) {
                    $actions = '<div class="btn-group" role="group">';
                    $actions .= '<a href="' . route('settings.job-logs.show', $row->id) . '" class="btn btn-sm btn-info" title="View Details">';
                    $actions .= '<i class="bx bx-show"></i>';
                    $actions .= '</a>';
                    $actions .= '</div>';
                    return $actions;
                })
                ->rawColumns(['status_badge', 'actions'])
                ->with([
                    'summary' => [
                        'total' => $totalJobs,
                        'completed' => $completedJobs,
                        'failed' => $failedJobs,
                        'running' => $runningJobs,
                    ]
                ])
                ->make(true);
        } catch (\Exception $e) {
            \Log::error('Job Logs DataTable Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'An error occurred while loading job logs data.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show Job Log Details
     */
    public function jobLogsShow($id)
    {
        // Check permissions
        if (!auth()->user()->can('view logs activity')) {
            abort(403, 'You do not have permission to view job logs.');
        }

        $jobLog = \App\Models\JobLog::findOrFail($id);
        
        // Get cached details if available
        $details = \Illuminate\Support\Facades\Cache::get('penalty_accrual_job_details_' . $id, []);

        return view('settings.job-logs.show', compact('jobLog', 'details'));
    }

    /**
     * Export Job Log
     */
    public function jobLogsExport(Request $request, $jobLog)
    {
        // Check permissions
        if (!auth()->user()->can('view logs activity')) {
            abort(403, 'You do not have permission to export job logs.');
        }

        // If $jobLog is an ID, find the model
        if (is_numeric($jobLog)) {
            $jobLog = \App\Models\JobLog::findOrFail($jobLog);
        }
        $format = $request->get('format', 'pdf'); // 'pdf' or 'excel'
        
        // Get cached details if available
        $details = \Illuminate\Support\Facades\Cache::get('penalty_accrual_job_details_' . $id, []);
        
        // Get company information
        $company = Company::find(current_company_id());
        $exportDate = now()->format('d-m-Y H:i:s');

        if ($format === 'excel') {
            // For Excel export, we'll use a simple approach
            // You can create a dedicated Export class if needed
            return $this->exportJobLogToExcel($jobLog, $details, $company, $exportDate);
        } else {
            // PDF export
            $viewName = 'settings.job-logs.pdf-penalty';
            if ($jobLog->job_name === 'CalculateDailyInterestJob') {
                $viewName = 'settings.job-logs.pdf-interest';
            }
            
            $pdf = Pdf::loadView($viewName, compact('jobLog', 'details', 'company', 'exportDate'));
            $filename = 'job_log_' . $jobLog->job_name . '_' . $jobLog->id . '_' . now()->format('Y_m_d_H_i_s') . '.pdf';
            
            return $pdf->download($filename);
        }
    }

    /**
     * Export Job Log to Excel
     */
    private function exportJobLogToExcel($jobLog, $details, $company, $exportDate)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set title
        $sheet->setCellValue('A1', ($company->name ?? 'Company Name') . ' - Job Log Details');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        
        // Job Information
        $row = 3;
        $sheet->setCellValue('A' . $row, 'Job Name:');
        $sheet->setCellValue('B' . $row, $jobLog->job_name);
        $row++;
        $sheet->setCellValue('A' . $row, 'Status:');
        $sheet->setCellValue('B' . $row, ucfirst($jobLog->status));
        $row++;
        $sheet->setCellValue('A' . $row, 'Started At:');
        $sheet->setCellValue('B' . $row, $jobLog->started_at ? $jobLog->started_at->format('d-m-Y H:i:s') : 'N/A');
        $row++;
        $sheet->setCellValue('A' . $row, 'Completed At:');
        $sheet->setCellValue('B' . $row, $jobLog->completed_at ? $jobLog->completed_at->format('d-m-Y H:i:s') : 'N/A');
        $row++;
        $sheet->setCellValue('A' . $row, 'Duration:');
        $sheet->setCellValue('B' . $row, $jobLog->formatted_duration);
        $row++;
        $sheet->setCellValue('A' . $row, 'Total Processed:');
        $sheet->setCellValue('B' . $row, $jobLog->processed);
        $row++;
        $sheet->setCellValue('A' . $row, 'Successful:');
        $sheet->setCellValue('B' . $row, $jobLog->successful);
        $row++;
        $sheet->setCellValue('A' . $row, 'Failed:');
        $sheet->setCellValue('B' . $row, $jobLog->failed);
        $row++;
        $sheet->setCellValue('A' . $row, 'Total Amount:');
        $sheet->setCellValue('B' . $row, $jobLog->total_amount ? 'TZS ' . number_format($jobLog->total_amount, 2) : 'N/A');
        
        // Details table
        if (!empty($details)) {
            $row += 2;
            $headers = [];
            if ($jobLog->job_name === 'CalculateDailyInterestJob') {
                $headers = ['#', 'Loan No', 'Customer Name', 'Principal Balance', 'Interest Accrued', 'Status'];
            } else {
                $headers = ['#', 'Loan No', 'Customer Name', 'Due Date', 'Base Amount', 'Penalty Rate', 'Penalty Amount', 'Status'];
            }
            
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $row, $header);
                $sheet->getStyle($col . $row)->getFont()->setBold(true);
                $col++;
            }
            $row++;
            
            foreach ($details as $index => $detail) {
                $col = 'A';
                $sheet->setCellValue($col . $row, $index + 1);
                $col++;
                
                if ($jobLog->job_name === 'CalculateDailyInterestJob') {
                    $sheet->setCellValue($col . $row, $detail['loan_no'] ?? 'N/A');
                    $col++;
                    $sheet->setCellValue($col . $row, $detail['customer_name'] ?? 'N/A');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['principal_balance']) ? number_format($detail['principal_balance'], 2) : '-');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['interest_accrued']) ? number_format($detail['interest_accrued'], 2) : '-');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['error']) ? 'Failed' : 'Success');
                } else {
                    $sheet->setCellValue($col . $row, $detail['loan_no'] ?? 'N/A');
                    $col++;
                    $sheet->setCellValue($col . $row, $detail['customer_name'] ?? 'N/A');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['due_date']) ? \Carbon\Carbon::parse($detail['due_date'])->format('d-m-Y') : 'N/A');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['base_amount']) ? number_format($detail['base_amount'], 2) : '-');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['penalty_rate']) ? number_format($detail['penalty_rate'], 2) . '%' : '-');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['penalty_amount']) ? number_format($detail['penalty_amount'], 2) : '-');
                    $col++;
                    $sheet->setCellValue($col . $row, isset($detail['error']) ? 'Failed' : 'Success');
                }
                $row++;
            }
        }
        
        // Auto-size columns
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'job_log_' . $jobLog->job_name . '_' . $jobLog->id . '_' . now()->format('Y_m_d_H_i_s') . '.xlsx';
        
        $tempFile = tempnam(sys_get_temp_dir(), 'job_log');
        $writer->save($tempFile);
        
        return response()->download($tempFile, $filename)->deleteFileAfterSend();
    }

    /**
     * Run Penalty Accrual Job
     */
    public function runPenaltyAccrual(Request $request)
    {
        // Check permissions
        if (!auth()->user()->can('manage penalty setting')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to run penalty accrual.'
            ], 403);
        }

        try {
            // Get optional accrual date from request, default to today
            $accrualDate = $request->input('accrual_date', now()->toDateString());

            // Run the penalty accrual job synchronously for immediate processing
            $job = new AccruePenaltyJob($accrualDate, true, null, true);
            dispatch_sync($job);

            return response()->json([
                'success' => true,
                'message' => 'Penalty accrual job has been completed successfully. Check Job Logs for details.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to dispatch penalty accrual job: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start penalty accrual job: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Run Daily Accrual Interest Job
     */
    /**
     * Run all daily scheduled loan/accounting jobs (same order as cron).
     */
    public function runDailyBatch(Request $request)
    {
        if (!auth()->user()->can('manage penalty setting')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to run scheduled jobs.',
            ], 403);
        }

        try {
            $date = $request->input('accrual_date', now()->toDateString());
            $params = ['--date' => $date];

            \Illuminate\Support\Facades\Artisan::call('subscription:check-expiry');
            \Illuminate\Support\Facades\Artisan::call('accounting:accrue-penalties', [
                '--sync' => true,
                '--no-daily-interest' => true,
                '--date' => $date,
            ]);
            \Illuminate\Support\Facades\Artisan::call('loans:accrue-daily-interest', ['--date' => $date]);
            \Illuminate\Support\Facades\Artisan::call('loans:collect-mature-interest');

            return response()->json([
                'success' => true,
                'message' => 'Daily batch completed (subscription, penalties, daily interest, mature interest). Check Job Logs.',
            ]);
        } catch (\Exception $e) {
            \Log::error('Daily batch failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Daily batch failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function runDailyAccrualInterest(Request $request)
    {
        // Check permissions
        if (!auth()->user()->can('manage penalty setting')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to run daily accrual interest.'
            ], 403);
        }

        try {
            // Get optional accrual date from request, default to today
            $accrualDate = $request->input('accrual_date', now()->toDateString());

            // Run the daily interest accrual job synchronously for immediate processing
            $job = new CalculateDailyInterestJob($accrualDate);
            dispatch_sync($job);

            return response()->json([
                'success' => true,
                'message' => 'Daily accrual interest job has been completed successfully. Check Job Logs for details.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to dispatch daily accrual interest job: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start daily accrual interest job: ' . $e->getMessage()
            ], 500);
        }
    }
}
