<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class BackupService
{
    protected $backupPath = 'backups';
    protected $tempPath = 'temp';

    public function __construct()
    {
        // Create backup directories if they don't exist
        $backupDir = storage_path("app/{$this->backupPath}");
        $tempDir = storage_path("app/{$this->tempPath}");
        
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
    }

    /**
     * Run backup for an existing in-progress backup record (used by background job).
     * Updates the backup with file path, size, and status when done.
     */
    public function runBackupFor(Backup $backup): void
    {
        if ($backup->status !== 'in_progress') {
            return;
        }

        try {
            $result = match ($backup->type) {
                'database' => $this->executeDatabaseBackup(),
                'files' => $this->executeFilesBackup(),
                'full' => $this->executeFullBackup(),
                default => throw new \Exception('Invalid backup type'),
            };

            $backup->update([
                'name' => $result['name'],
                'filename' => $result['filename'],
                'file_path' => $result['file_path'],
                'size' => $result['size'],
                'status' => 'completed',
            ]);
        } catch (\Exception $e) {
            $backup->update([
                'status' => 'failed',
                'name' => ($backup->type === 'database' ? 'Database' : ($backup->type === 'files' ? 'Files' : 'Full')) . ' Backup - ' . date('Y-m-d H:i:s'),
            ]);
            throw $e;
        }
    }

    /**
     * Execute database backup (with data). Returns result array for creating/updating record.
     */
    protected function executeDatabaseBackup(): array
    {
        $filename = 'database_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filePath = $this->backupPath . '/' . $filename;
        $fullPath = storage_path("app/{$filePath}");

        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');

        $command = "mysqldump -h {$host} -P {$port} -u {$username}";
        if ($password) {
            $command .= " -p{$password}";
        }
        $command .= " {$database} > {$fullPath}";

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Database backup failed');
        }

        $size = file_exists($fullPath) ? filesize($fullPath) : 0;

        return [
            'name' => 'Database Backup - ' . date('Y-m-d H:i:s'),
            'filename' => $filename,
            'file_path' => $filePath,
            'size' => $size,
        ];
    }

    /**
     * Create a database backup (synchronous; for backwards compatibility).
     */
    public function createDatabaseBackup($description = null)
    {
        try {
            $result = $this->executeDatabaseBackup();
            return Backup::create(array_merge($result, [
                'type' => 'database',
                'description' => $description,
                'status' => 'completed',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]));
        } catch (\Exception $e) {
            Backup::create([
                'name' => 'Database Backup - ' . date('Y-m-d H:i:s'),
                'filename' => 'failed_backup.sql',
                'file_path' => $this->backupPath . '/failed_backup.sql',
                'type' => 'database',
                'size' => 0,
                'description' => $description,
                'status' => 'failed',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]);
            throw $e;
        }
    }

    /**
     * Execute files backup. Returns result array for creating/updating record.
     */
    protected function executeFilesBackup(): array
    {
        $filename = 'files_backup_' . date('Y-m-d_H-i-s') . '.zip';
        $filePath = $this->backupPath . '/' . $filename;
        $fullPath = storage_path("app/{$filePath}");
        $tempZipPath = storage_path("app/{$this->tempPath}/temp_files.zip");

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE) !== true) {
            throw new \Exception('Could not create ZIP file');
        }

        $this->addDirectoryToZip($zip, storage_path('app/public'), 'storage/app/public');
        $this->addDirectoryToZip($zip, public_path('uploads'), 'public/uploads');

        $zip->close();

        if (!Storage::move($this->tempPath . '/temp_files.zip', $filePath)) {
            Storage::copy($this->tempPath . '/temp_files.zip', $filePath);
            Storage::delete($this->tempPath . '/temp_files.zip');
        }

        $size = file_exists($fullPath) ? filesize($fullPath) : 0;

        return [
            'name' => 'Files Backup - ' . date('Y-m-d H:i:s'),
            'filename' => $filename,
            'file_path' => $filePath,
            'size' => $size,
        ];
    }

    /**
     * Create a files backup (synchronous; for backwards compatibility).
     */
    public function createFilesBackup($description = null)
    {
        try {
            $result = $this->executeFilesBackup();
            return Backup::create(array_merge($result, [
                'type' => 'files',
                'description' => $description,
                'status' => 'completed',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]));
        } catch (\Exception $e) {
            Backup::create([
                'name' => 'Files Backup - ' . date('Y-m-d H:i:s'),
                'filename' => 'failed_backup.zip',
                'file_path' => $this->backupPath . '/failed_backup.zip',
                'type' => 'files',
                'size' => 0,
                'description' => $description,
                'status' => 'failed',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]);
            throw $e;
        }
    }

    /**
     * Execute full backup (database with data + files). Returns result array for creating/updating record.
     */
    protected function executeFullBackup(): array
    {
        $filename = 'full_backup_' . date('Y-m-d_H-i-s') . '.zip';
        $filePath = $this->backupPath . '/' . $filename;
        $fullPath = storage_path("app/{$filePath}");
        $tempZipPath = storage_path("app/{$this->tempPath}/temp_full.zip");

        $dbFilename = 'database_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $dbFilePath = storage_path("app/{$this->backupPath}/{$dbFilename}");

        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');

        $command = "mysqldump -h {$host} -P {$port} -u {$username}";
        if ($password) {
            $command .= " -p{$password}";
        }
        $command .= " {$database} > {$dbFilePath}";

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Database backup failed');
        }

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE) !== true) {
            throw new \Exception('Could not create ZIP file');
        }

        if (File::exists($dbFilePath)) {
            $zip->addFile($dbFilePath, 'database/' . $dbFilename);
        }

        $this->addDirectoryToZip($zip, storage_path('app/public'), 'storage/app/public');
        $this->addDirectoryToZip($zip, public_path('uploads'), 'public/uploads');

        $zip->close();

        if (!Storage::move($this->tempPath . '/temp_full.zip', $filePath)) {
            Storage::copy($this->tempPath . '/temp_full.zip', $filePath);
            Storage::delete($this->tempPath . '/temp_full.zip');
        }

        if (file_exists($dbFilePath)) {
            unlink($dbFilePath);
        }

        $size = file_exists($fullPath) ? filesize($fullPath) : 0;

        return [
            'name' => 'Full Backup - ' . date('Y-m-d H:i:s'),
            'filename' => $filename,
            'file_path' => $filePath,
            'size' => $size,
        ];
    }

    /**
     * Create a full backup (synchronous; for backwards compatibility).
     */
    public function createFullBackup($description = null)
    {
        try {
            $result = $this->executeFullBackup();
            return Backup::create(array_merge($result, [
                'type' => 'full',
                'description' => $description,
                'status' => 'completed',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]));
        } catch (\Exception $e) {
            Backup::create([
                'name' => 'Full Backup - ' . date('Y-m-d H:i:s'),
                'filename' => 'failed_backup.zip',
                'file_path' => $this->backupPath . '/failed_backup.zip',
                'type' => 'full',
                'size' => 0,
                'description' => $description,
                'status' => 'failed',
                'created_by' => auth()->id(),
                'company_id' => current_company_id(),
            ]);
            throw $e;
        }
    }

    /**
     * Restore from backup
     */
    public function restoreBackup(Backup $backup)
    {
        try {
            $filePath = storage_path("app/{$backup->file_path}");
            
            if (!file_exists($filePath)) {
                throw new \Exception('Backup file not found');
            }

            if ($backup->type === 'database') {
                return $this->restoreDatabaseBackup($filePath);
            } elseif ($backup->type === 'files') {
                return $this->restoreFilesBackup($filePath);
            } elseif ($backup->type === 'full') {
                return $this->restoreFullBackup($filePath);
            }

            throw new \Exception('Unsupported backup type');

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Restore database from SQL file
     */
    protected function restoreDatabaseBackup($filePath)
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');

        $command = "mysql -h {$host} -P {$port} -u {$username}";
        if ($password) {
            $command .= " -p{$password}";
        }
        $command .= " {$database} < {$filePath}";

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Database restore failed');
        }

        return true;
    }

    /**
     * Restore files from ZIP
     */
    protected function restoreFilesBackup($filePath)
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            throw new \Exception('Could not open ZIP file');
        }

        $zip->extractTo(storage_path('app/'));
        $zip->close();

        return true;
    }

    /**
     * Restore full backup
     */
    protected function restoreFullBackup($filePath)
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            throw new \Exception('Could not open ZIP file');
        }

        // Extract to temp directory
        $tempExtractPath = storage_path("app/{$this->tempPath}/restore");
        if (File::exists($tempExtractPath)) {
            File::deleteDirectory($tempExtractPath);
        }
        File::makeDirectory($tempExtractPath, 0755, true);

        $zip->extractTo($tempExtractPath);
        $zip->close();

        // Restore database
        $dbFile = $tempExtractPath . '/database/' . File::files($tempExtractPath . '/database')[0];
        $this->restoreDatabaseBackup($dbFile);

        // Restore files
        if (File::exists($tempExtractPath . '/storage')) {
            File::copyDirectory($tempExtractPath . '/storage', storage_path('app/'));
        }
        if (File::exists($tempExtractPath . '/public')) {
            File::copyDirectory($tempExtractPath . '/public', public_path());
        }

        // Clean up
        File::deleteDirectory($tempExtractPath);

        return true;
    }

    /**
     * Add directory to ZIP archive
     */
    protected function addDirectoryToZip($zip, $dirPath, $zipPath)
    {
        if (!File::exists($dirPath)) {
            return;
        }

        $files = File::allFiles($dirPath);
        foreach ($files as $file) {
            $relativePath = str_replace($dirPath . '/', '', $file->getPathname());
            $zip->addFile($file->getPathname(), $zipPath . '/' . $relativePath);
        }
    }

    /**
     * Get backup statistics
     */
    public function getBackupStats()
    {
        $companyId = current_company_id();
        
        return [
            'total' => Backup::forCompany()->count(),
            'database' => Backup::forCompany()->byType('database')->count(),
            'files' => Backup::forCompany()->byType('files')->count(),
            'full' => Backup::forCompany()->byType('full')->count(),
            'completed' => Backup::forCompany()->completed()->count(),
            'failed' => Backup::forCompany()->failed()->count(),
            'total_size' => Backup::forCompany()->completed()->sum('size'),
        ];
    }

    /**
     * Clean old backups
     */
    public function cleanOldBackups($days = 30)
    {
        $oldBackups = Backup::forCompany()
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        foreach ($oldBackups as $backup) {
            $backup->deleteFile();
            $backup->delete();
        }

        return $oldBackups->count();
    }
} 