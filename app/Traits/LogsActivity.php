<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Jenssegers\Agent\Agent;

trait LogsActivity
{
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            $model->storeActivityLog('create');
        });

        static::updated(function ($model) {
            $model->storeActivityLog('update');
        });

        static::deleted(function ($model) {
            $model->storeActivityLog('delete', $model->getAttributes());
        });
    }

    protected function storeActivityLog($action, $deletedData = null)
    {
        $agent = new Agent();
        $deviceInfo = 'Unknown';
        if ($agent->isDesktop()) {
            $deviceInfo = 'Desktop';
        } elseif ($agent->isPhone()) {
            if ($agent->is('iPhone')) {
                $deviceInfo = 'iPhone';
            } elseif ($agent->is('AndroidOS')) {
                $deviceInfo = 'Android Phone';
            } else {
                $deviceInfo = 'Phone';
            }
        } elseif ($agent->isTablet()) {
            if ($agent->is('iPad')) {
                $deviceInfo = 'iPad';
            } else {
                $deviceInfo = 'Tablet';
            }
        }

        $deviceString = $deviceInfo . ' - ' . $agent->browser();

        // Build description with identifying information
        $description = $this->buildDescription($action, $deletedData);

        $logData = [
            'user_id'     => Auth::id(),
            'model'       => class_basename($this),
            'action'      => $action,
            'description' => $description,
            'ip_address'  => request()->ip(),
            'device'      => $deviceString,
            'activity_time' => now(),
        ];

        // For update actions, capture old and new values
        if ($action === 'update') {
            $changes = $this->getChanges();
            $original = $this->getOriginal();

            // Build old_values and new_values arrays with only the changed fields
            $oldValues = [];
            $newValues = [];

            foreach ($changes as $key => $newValue) {
                // Skip timestamps and internal fields
                if (in_array($key, ['updated_at', 'created_at', 'deleted_at'])) {
                    continue;
                }

                $oldValues[$key] = $original[$key] ?? null;
                $newValues[$key] = $newValue;
            }

            if (!empty($oldValues)) {
                $logData['old_values'] = $oldValues;
                $logData['new_values'] = $newValues;
            }
        }

        // For delete actions, capture the deleted record's data
        if ($action === 'delete' && $deletedData) {
            $filteredData = [];
            foreach ($deletedData as $key => $value) {
                // Skip timestamps, passwords, and other sensitive fields
                $skipFields = ['updated_at', 'created_at', 'deleted_at', 'password', 'remember_token'];
                if (in_array($key, $skipFields)) {
                    continue;
                }
                $filteredData[$key] = $value;
            }

            if (!empty($filteredData)) {
                $logData['old_values'] = $filteredData;
            }
        }

        ActivityLog::create($logData);
    }

    /**
     * Build a descriptive message for the activity log
     */
    protected function buildDescription($action, $deletedData = null): string
    {
        $modelName = class_basename($this);
        $identifier = $this->getIdentifier($deletedData);

        $actionVerb = match ($action) {
            'create' => 'created',
            'update' => 'updated',
            'delete' => 'deleted',
            default => $action . 'd',
        };

        if ($identifier) {
            return ucfirst($actionVerb) . " {$modelName}: {$identifier}";
        }

        return ucfirst($actionVerb) . " {$modelName} (ID: {$this->id})";
    }

    /**
     * Get a human-readable identifier for the model
     */
    protected function getIdentifier($deletedData = null): ?string
    {
        // Common field names that identify a record
        $identifierFields = [
            'name', 'title', 'code', 'reference',
            'email', 'username', 'account_number', 'account_name'
        ];

        $data = $deletedData ?? $this->getAttributes();

        foreach ($identifierFields as $field) {
            if (!empty($data[$field])) {
                return $data[$field];
            }
        }

        return null;
    }
}
