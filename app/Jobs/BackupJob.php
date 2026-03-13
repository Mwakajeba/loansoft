<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\JobLog;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes for full backup
    public $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $type  database|full
     * @param  int  $userId  User who requested the backup
     * @param  int  $companyId  Company context for the backup
     * @param  string|null  $description  Optional description
     * @param  int|null  $jobLogId  Optional JobLog id for status tracking (like ReminderSmsJob)
     */
    public function __construct(
        protected string $type,
        protected int $userId,
        protected int $companyId,
        protected ?string $description = null,
        protected ?int $jobLogId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Set company context so BackupService and scopes work correctly
        config(['app.current_company_id' => $this->companyId]);

        if ($this->jobLogId) {
            JobLog::where('id', $this->jobLogId)->update(['status' => 'running']);
        }

        $backup = Backup::create([
            'name' => $this->getPendingName(),
            'filename' => 'pending',
            'file_path' => 'backups/pending',
            'type' => $this->type,
            'size' => 0,
            'description' => $this->description,
            'status' => 'in_progress',
            'created_by' => $this->userId,
            'company_id' => $this->companyId,
        ]);

        $startTime = now();

        try {
            $backupService = new BackupService();
            $backupService->runBackupFor($backup);
            $backup->refresh();

            if ($this->jobLogId) {
                $current = JobLog::find($this->jobLogId);
                $details = is_array($current->result_details ?? null) ? $current->result_details : [];
                $details = array_merge($details, ['backup_id' => $backup->id, 'type' => $this->type]);
                JobLog::where('id', $this->jobLogId)->update([
                    'status' => 'completed',
                    'summary' => ucfirst($this->type) . ' backup completed: ' . $backup->name . ' (' . $backup->formatted_size . ')',
                    'completed_at' => now(),
                    'duration_seconds' => now()->diffInSeconds($startTime),
                    'result_details' => $details,
                ]);
            }

            Log::info("Backup completed successfully", ['backup_id' => $backup->id, 'type' => $this->type]);
        } catch (\Exception $e) {
            if ($this->jobLogId) {
                JobLog::where('id', $this->jobLogId)->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                    'duration_seconds' => now()->diffInSeconds($startTime),
                ]);
            }
            Log::error('Backup job failed: ' . $e->getMessage(), [
                'backup_id' => $backup->id,
                'type' => $this->type,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->jobLogId) {
            JobLog::where('id', $this->jobLogId)->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }
        Log::error('BackupJob failed', ['job_log_id' => $this->jobLogId, 'error' => $exception->getMessage()]);
    }

    protected function getPendingName(): string
    {
        $label = match ($this->type) {
            'database' => 'Database Backup',
            'files' => 'Files Backup',
            'full' => 'Full Backup',
            default => 'Backup',
        };
        return $label . ' - ' . date('Y-m-d H:i:s');
    }
}
