<?php

namespace App\Console\Commands;

use App\Jobs\ImportDatabaseJob;
use App\Models\DatabaseImport;
use App\Services\BackupService;
use Illuminate\Console\Command;

class ProcessDatabaseImportCommand extends Command
{
    protected $signature = 'database-import:process {id? : Database import ID (defaults to oldest queued)}';

    protected $description = 'Process a queued database import (run without a queue worker)';

    public function handle(BackupService $backupService): int
    {
        $id = $this->argument('id');

        if ($id) {
            $import = DatabaseImport::find($id);
        } else {
            $import = DatabaseImport::query()
                ->where('status', DatabaseImport::STATUS_QUEUED)
                ->orderBy('id')
                ->first();
        }

        if (! $import) {
            $this->error('No queued database import found.');

            return self::FAILURE;
        }

        if ($import->status === DatabaseImport::STATUS_PROCESSING) {
            $this->warn("Import #{$import->id} is already processing.");

            return self::SUCCESS;
        }

        if ($import->status === DatabaseImport::STATUS_COMPLETED) {
            $this->info("Import #{$import->id} is already completed.");

            return self::SUCCESS;
        }

        $this->info("Processing database import #{$import->id} ({$import->original_filename})...");

        try {
            (new ImportDatabaseJob((int) $import->id))->handle($backupService);
            $fresh = DatabaseImport::find($import->id);
            $this->info('Status: '.($fresh->status ?? 'unknown'));
            $this->line($fresh->message ?? '');

            return ($fresh && $fresh->status === DatabaseImport::STATUS_COMPLETED)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
