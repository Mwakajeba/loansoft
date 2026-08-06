<?php

namespace App\Jobs;

use App\Models\DatabaseImport;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours for large dumps

    public $tries = 1;

    public $maxExceptions = 1;

    public function __construct(public int $importId)
    {
    }

    public function handle(BackupService $backupService): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('database-import-job-'.$this->importId, 7200);
        if (! $lock->get()) {
            Log::info('ImportDatabaseJob skipped — already running', ['import_id' => $this->importId]);

            return;
        }

        try {
            /** @var DatabaseImport|null $import */
            $import = DatabaseImport::find($this->importId);
            if (! $import) {
                Log::error('ImportDatabaseJob: import record not found', ['id' => $this->importId]);

                return;
            }

            if ($import->status === DatabaseImport::STATUS_COMPLETED) {
                Log::info('ImportDatabaseJob skipped — already completed', ['import_id' => $this->importId]);

                return;
            }

            if ($import->status === DatabaseImport::STATUS_PROCESSING
                && $import->started_at
                && $import->started_at->gt(now()->subMinutes(5))) {
                Log::info('ImportDatabaseJob skipped — recently processing', ['import_id' => $this->importId]);

                return;
            }

            $import->status = DatabaseImport::STATUS_PROCESSING;
            $import->started_at = now();
            $import->message = 'Import started.';
            $import->save();
            $import->syncCacheStatus();

            $absolutePath = storage_path('app/'.$import->stored_path);

            try {
                Log::info('ImportDatabaseJob started', [
                    'import_id' => $import->id,
                    'mode' => $import->mode,
                    'file' => $import->original_filename,
                    'size' => $import->file_size,
                ]);

                $result = $backupService->importUploadedDatabase(
                    $absolutePath,
                    $import->mode,
                    $import->created_by,
                    $import->company_id
                );

                DB::reconnect();

                $message = $import->mode === DatabaseImport::MODE_BACKUP
                    ? 'Database imported successfully. A safety backup was created first.'
                    : 'Existing database replaced by import (no safety backup).';

                $fresh = DatabaseImport::find($this->importId);
                if ($fresh) {
                    $fresh->status = DatabaseImport::STATUS_COMPLETED;
                    $fresh->message = $message;
                    $fresh->backup_id = $result['backup']->id ?? null;
                    $fresh->finished_at = now();
                    $fresh->save();
                    $fresh->syncCacheStatus();
                } else {
                    $import->status = DatabaseImport::STATUS_COMPLETED;
                    $import->message = $message.' (import history was overwritten by the dump.)';
                    $import->finished_at = now();
                    $import->syncCacheStatus();
                }

                Log::info('ImportDatabaseJob completed', ['import_id' => $this->importId]);
            } catch (\Throwable $e) {
                Log::error('ImportDatabaseJob failed', [
                    'import_id' => $this->importId,
                    'error' => $e->getMessage(),
                ]);

                try {
                    DB::reconnect();
                    $fresh = DatabaseImport::find($this->importId);
                    if ($fresh) {
                        $fresh->status = DatabaseImport::STATUS_FAILED;
                        $fresh->message = $e->getMessage();
                        $fresh->finished_at = now();
                        $fresh->save();
                        $fresh->syncCacheStatus();
                    } else {
                        $import->status = DatabaseImport::STATUS_FAILED;
                        $import->message = $e->getMessage();
                        $import->finished_at = now();
                        $import->syncCacheStatus();
                    }
                } catch (\Throwable $inner) {
                    $import->status = DatabaseImport::STATUS_FAILED;
                    $import->message = $e->getMessage();
                    $import->finished_at = now();
                    $import->syncCacheStatus();
                    Log::error('ImportDatabaseJob could not persist failure status', [
                        'error' => $inner->getMessage(),
                    ]);
                }

                throw $e;
            }
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            $import = DatabaseImport::find($this->importId);
            if ($import && $import->status !== DatabaseImport::STATUS_COMPLETED) {
                $import->status = DatabaseImport::STATUS_FAILED;
                $import->message = $exception?->getMessage() ?? 'Import job failed.';
                $import->finished_at = now();
                $import->save();
                $import->syncCacheStatus();
            }
        } catch (\Throwable $e) {
            Log::error('ImportDatabaseJob failed() could not update status', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
