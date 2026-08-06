<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class DatabaseImport extends Model
{
    public const MODE_BACKUP = 'backup';

    public const MODE_REPLACE = 'replace';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'original_filename',
        'stored_path',
        'file_size',
        'mode',
        'status',
        'message',
        'backup_id',
        'created_by',
        'company_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }

    public function scopeForCompany($query)
    {
        return $query->where('company_id', current_company_id());
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function cacheKey(): string
    {
        return 'database_import_status_'.$this->id;
    }

    public function syncCacheStatus(): void
    {
        Cache::put($this->cacheKey(), [
            'id' => $this->id,
            'status' => $this->status,
            'message' => $this->message,
            'mode' => $this->mode,
            'original_filename' => $this->original_filename,
            'started_at' => optional($this->started_at)?->toDateTimeString(),
            'finished_at' => optional($this->finished_at)?->toDateTimeString(),
        ], now()->addDays(7));
    }

    public static function cachedStatus(int $id): ?array
    {
        $cached = Cache::get('database_import_status_'.$id);

        return is_array($cached) ? $cached : null;
    }
}
