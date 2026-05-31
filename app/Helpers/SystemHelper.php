<?php

if (!function_exists('setting')) {
    /**
     * Get a system setting value
     */
    function setting($key, $default = null)
    {
        return \App\Services\SystemSettingService::get($key, $default);
    }
}

if (!function_exists('app_setting')) {
    /**
     * Get application setting
     */
    function app_setting($key, $default = null)
    {
        return \App\Services\SystemSettingService::get($key, $default);
    }
}

if (!function_exists('microfinance_setting')) {
    /**
     * Get microfinance specific setting
     */
    function microfinance_setting($key, $default = null)
    {
        return \App\Services\SystemSettingService::get($key, $default);
    }
}

if (!function_exists('is_maintenance_mode')) {
    /**
     * Check if maintenance mode is enabled
     */
    function is_maintenance_mode()
    {
        return \App\Services\SystemSettingService::isMaintenanceMode();
    }
}

if (!function_exists('get_maintenance_message')) {
    /**
     * Get maintenance message
     */
    function get_maintenance_message()
    {
        return \App\Services\SystemSettingService::getMaintenanceMessage();
    }
}

if (!function_exists('format_currency')) {
    /**
     * Format currency based on system settings
     */
    function format_currency($amount, $currency = null)
    {
        $currency = $currency ?: setting('currency', 'TZS');
        $symbol = setting('currency_symbol', 'TSh');
        
        return $symbol . number_format($amount, 2);
    }
}

if (!function_exists('format_date')) {
    /**
     * Format date based on system settings
     */
    function format_date($date, $format = null)
    {
        $format = $format ?: setting('date_format', 'Y-m-d');
        
        if ($date instanceof \Carbon\Carbon) {
            return $date->format($format);
        }
        
        return \Carbon\Carbon::parse($date)->format($format);
    }
}

if (!function_exists('format_datetime')) {
    /**
     * Format datetime based on system settings
     */
    function format_datetime($datetime, $dateFormat = null, $timeFormat = null)
    {
        $dateFormat = $dateFormat ?: setting('date_format', 'Y-m-d');
        $timeFormat = $timeFormat ?: setting('time_format', 'H:i:s');
        $format = $dateFormat . ' ' . $timeFormat;
        
        if ($datetime instanceof \Carbon\Carbon) {
            return $datetime->format($format);
        }
        
        return \Carbon\Carbon::parse($datetime)->format($format);
    }
}

if (!function_exists('dcb_gateway_configured')) {
    /**
     * DCB API credentials are present (settings saved).
     */
    function dcb_gateway_configured(): bool
    {
        return app(\App\Services\DcbGatewayService::class)->isConfigured();
    }
}

if (!function_exists('dcb_payments_enabled')) {
    /**
     * DCB is turned on and ready to process payments.
     */
    function dcb_payments_enabled(): bool
    {
        return app(\App\Services\DcbPaymentService::class)->isEnabled();
    }
}

if (!function_exists('dcb_show_on_loans_ui')) {
    /**
     * Show DCB options on loan screens (configured or explicitly enabled).
     */
    function dcb_show_on_loans_ui(): bool
    {
        if (dcb_payments_enabled()) {
            return true;
        }

        return dcb_gateway_configured();
    }
}

if (!function_exists('update_env_file')) {
    /**
     * Update or add environment variable in .env file
     */
    function update_env_file($key, $value)
    {
        $envFile = base_path('.env');
        
        if (!file_exists($envFile)) {
            return false;
        }
        
        // Read the .env file
        $envContent = file_get_contents($envFile);
        
        // Handle value escaping - wrap in quotes if it contains spaces or special characters
        $needsQuotes = preg_match('/[\s#\$"\'\\\]/', $value);
        if ($needsQuotes) {
            // Escape quotes and backslashes in the value
            $escapedValue = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        } else {
            $escapedValue = $value;
        }
        
        // Use a line-by-line approach (avoids preg_replace corrupting values that
        // contain {placeholders} which PCRE interprets as named backreferences)
        $lines = explode("\n", $envContent);
        $found = false;
        $keyPrefix = $key . '=';

        foreach ($lines as &$line) {
            // Match the key exactly (handle optional existing quotes on the value)
            if (str_starts_with($line, $keyPrefix)) {
                $line = $key . '=' . $escapedValue;
                $found = true;
                break;
            }
        }
        unset($line);

        if (!$found) {
            // Add new key after the last non-empty, non-comment line
            $lastNonEmptyIndex = -1;
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $trimmed = trim($lines[$i]);
                if (!empty($trimmed) && !str_starts_with($trimmed, '#')) {
                    $lastNonEmptyIndex = $i;
                    break;
                }
            }

            if ($lastNonEmptyIndex >= 0) {
                array_splice($lines, $lastNonEmptyIndex + 1, 0, $key . '=' . $escapedValue);
            } else {
                $lines[] = $key . '=' . $escapedValue;
            }
        }

        $envContent = implode("\n", $lines);
        
        // Write back to file
        return file_put_contents($envFile, $envContent) !== false;
    }
}

if (!function_exists('is_loopback_ip')) {
    function is_loopback_ip(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return true;
        }

        return in_array($ip, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'], true)
            || str_starts_with($ip, '127.');
    }
}

if (!function_exists('get_server_network_ip')) {
    /**
     * Primary non-loopback IPv4 address of this server (LAN/network IP).
     */
    function get_server_network_ip(): ?string
    {
        static $cached;

        if ($cached !== null) {
            return $cached !== '' ? $cached : null;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $output = @shell_exec('hostname -I 2>/dev/null');
            if ($output) {
                foreach (preg_split('/\s+/', trim($output)) as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                        && !is_loopback_ip($candidate)) {
                        return $cached = $candidate;
                    }
                }
            }
        }

        $hostname = gethostname();
        if ($hostname) {
            $resolved = gethostbyname($hostname);
            if ($resolved && $resolved !== $hostname && !is_loopback_ip($resolved)) {
                return $cached = $resolved;
            }
        }

        if (function_exists('socket_create')) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket) {
                @socket_connect($socket, '8.8.8.8', 53);
                @socket_getsockname($socket, $addr);
                socket_close($socket);
                if (!empty($addr) && !is_loopback_ip($addr)) {
                    return $cached = $addr;
                }
            }
        }

        return $cached = '';
    }
}

if (!function_exists('resolve_client_ip')) {
    /**
     * Resolve the client IP for auditing. Uses proxy headers when present;
     * when the request is local (127.0.0.1), returns the server network IP instead.
     */
    function resolve_client_ip(): ?string
    {
        $request = request();
        if (!$request) {
            return get_server_network_ip();
        }

        $ip = $request->ip();

        $forwarded = $request->header('X-Forwarded-For');
        if ($forwarded) {
            $parts = array_map('trim', explode(',', $forwarded));
            $candidate = $parts[0] ?? null;
            if ($candidate && filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        } elseif ($realIp = $request->header('X-Real-IP')) {
            $realIp = trim($realIp);
            if (filter_var($realIp, FILTER_VALIDATE_IP)) {
                $ip = $realIp;
            }
        }

        if (is_loopback_ip($ip)) {
            return get_server_network_ip() ?? $ip;
        }

        return $ip;
    }
}