<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobLog extends Model
{
    protected $fillable = [
        'job_name',
        'status',
        'processed',
        'successful',
        'failed',
        'total_amount',
        'summary',
        'error_message',
        'result_details',
        'started_at',
        'completed_at',
        'duration_seconds',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'result_details' => 'array',
    ];
    
    /**
     * Get the duration in human readable format
     */
    public function getDurationAttribute()
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }
        
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;
        
        if ($minutes > 0) {
            return "{$minutes}m {$seconds}s";
        }
        
        return "{$seconds}s";
    }
    
    /**
     * Scope to get logs for a specific job
     */
    public function scopeForJob($query, $jobName)
    {
        return $query->where('job_name', $jobName);
    }
    
    /**
     * Scope to get recent logs
     */
    public function scopeRecent($query, $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
