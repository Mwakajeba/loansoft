<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'model',
        'action',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'device',
        'activity_time',
    ];

    protected $casts = [
        // Store full date & time (including seconds) for activity timestamp
        'activity_time' => 'datetime',
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get a human-readable summary of what changed (for update/delete actions).
     * - For update: "name: John → Jane; status: pending → active"
     * - For delete: Shows the deleted record's key information
     */
    public function getChangesSummaryAttribute(): ?string
    {
        // For delete actions, show the deleted record's information
        if ($this->action === 'delete' && !empty($this->old_values)) {
            return $this->formatDeletedRecordInfo();
        }

        // For update actions, show before → after changes
        if ($this->action === 'update' && !empty($this->old_values) && !empty($this->new_values)) {
            return $this->formatUpdateChanges();
        }

        return null;
    }

    /**
     * Format the deleted record information
     */
    protected function formatDeletedRecordInfo(): string
    {
        $parts = [];
        $priorityFields = ['name', 'title', 'code', 'reference', 'email', 'account_number'];

        // Show priority fields first
        foreach ($priorityFields as $field) {
            if (isset($this->old_values[$field])) {
                $label = str_replace('_', ' ', ucfirst($field));
                $value = $this->formatValueForDisplay($this->old_values[$field]);
                $parts[] = "{$label}: {$value}";
            }
        }

        // Show other important fields (limit to avoid clutter)
        $shown = count($parts);
        foreach ($this->old_values as $key => $value) {
            if ($shown >= 5 || in_array($key, array_merge($priorityFields, ['id']))) {
                continue;
            }
            $label = str_replace('_', ' ', ucfirst($key));
            $valueStr = $this->formatValueForDisplay($value);
            $parts[] = "{$label}: {$valueStr}";
            $shown++;
        }

        return implode('; ', $parts);
    }

    /**
     * Format update changes (before → after)
     */
    protected function formatUpdateChanges(): string
    {
        $parts = [];
        foreach ($this->old_values as $key => $oldVal) {
            $newVal = $this->new_values[$key] ?? null;
            $oldStr = $this->formatValueForDisplay($oldVal);
            $newStr = $this->formatValueForDisplay($newVal);
            $label = str_replace('_', ' ', ucfirst($key));
            $parts[] = "{$label}: {$oldStr} → {$newStr}";
        }
        return implode('; ', $parts);
    }

    protected function formatValueForDisplay($value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d H:i');
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }
}
