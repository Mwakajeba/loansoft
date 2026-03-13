@extends('layouts.main')

@section('title', 'System Settings')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'System Settings', 'url' => '#', 'icon' => 'bx bx-cog']
        ]" />
        <h6 class="mb-0 text-uppercase">SYSTEM SETTINGS</h6>
        <hr/>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bx bx-check-circle me-2"></i>
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bx bx-x-circle me-2"></i>
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="card-title mb-0">System Configuration</h4>
                            <div>
                                @can('manage system configurations')
                                <button type="button" class="btn btn-warning btn-sm" onclick="confirmReset()">
                                    <i class="bx bx-refresh me-1"></i> Reset to Defaults
                                </button>
                                @endcan
                                <a href="{{ route('settings.index') }}" class="btn btn-secondary btn-sm">
                                    <i class="bx bx-arrow-back me-1"></i> Back to Settings
                                </a>
                            </div>
                        </div>

                        @can('edit system configurations')
                        <form action="{{ route('settings.system.update') }}" method="POST">
                            @csrf
                            @method('PUT')
                        @else
                        <div>
                        @endcan
                            
                            <!-- Navigation Tabs -->
                            <ul class="nav nav-tabs nav-bordered" id="settingsTabs" role="tablist">
                                @foreach($groups as $groupKey => $groupName)
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link {{ $loop->first ? 'active' : '' }}" 
                                                id="{{ $groupKey }}-tab" 
                                                data-bs-toggle="tab" 
                                                data-bs-target="#{{ $groupKey }}-content" 
                                                type="button" 
                                                role="tab">
                                            <i class="bx {{ $groupIcons[$groupKey] }} me-1"></i>
                                            {{ $groupName }}
                                        </button>
                                    </li>
                                @endforeach
                            </ul>

                            <!-- Tab Content -->
                            <div class="tab-content mt-3" id="settingsTabContent">
                                @foreach($groups as $groupKey => $groupName)
                                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" 
                                         id="{{ $groupKey }}-content" 
                                         role="tabpanel">

                                        {{-- Backup tab: link to the full Backup & Restore page --}}
                                        @if($groupKey === 'backup')
                                            <div class="alert alert-info d-flex align-items-center justify-content-between mb-3" role="alert">
                                                <div>
                                                    <i class="bx bx-data me-2 fs-5"></i>
                                                    <strong>Backup & Restore</strong> — create backups, view history, and restore data from the dedicated page.
                                                </div>
                                                <a href="{{ route('settings.backup') }}" class="btn btn-primary btn-sm ms-3 text-nowrap">
                                                    <i class="bx bx-link-external me-1"></i> Open Backup Settings
                                                </a>
                                            </div>
                                        @endif

                                        <div class="row">
                                            @foreach($settings[$groupKey] as $setting)
                                                <div class="col-md-6 mb-3">
                                                    <div class="form-group">
                                                        <label for="{{ $setting->key }}" class="form-label">
                                                            {{ $setting->label }}
                                                            @if($setting->description)
                                                                <i class="bx bx-info-circle text-muted" 
                                                                   data-bs-toggle="tooltip" 
                                                                   title="{{ $setting->description }}"></i>
                                                            @endif
                                                        </label>
                                                        
                                                        @if($setting->type === 'boolean')
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input" 
                                                                       type="checkbox" 
                                                                       id="{{ $setting->key }}" 
                                                                       name="settings[{{ $setting->key }}]" 
                                                                       value="1" 
                                                                       {{ $setting->value ? 'checked' : '' }}
                                                                       @cannot('edit system configurations') disabled @endcannot>
                                                                <label class="form-check-label" for="{{ $setting->key }}">
                                                                    Enable {{ $setting->label }}
                                                                </label>
                                                            </div>
                                                        @elseif($setting->type === 'integer')
                                                            <input type="number" 
                                                                   class="form-control" 
                                                                   id="{{ $setting->key }}" 
                                                                   name="settings[{{ $setting->key }}]" 
                                                                   value="{{ $setting->value }}" 
                                                                   min="0"
                                                                   @cannot('edit system configurations') readonly @endcannot>
                                                        @elseif($setting->key === 'timezone')
                                                            <select class="form-select" 
                                                                    id="{{ $setting->key }}" 
                                                                    name="settings[{{ $setting->key }}]"
                                                                    @cannot('edit system configurations') disabled @endcannot>
                                                                @foreach($timezones as $timezone)
                                                                    <option value="{{ $timezone }}" 
                                                                            {{ $setting->value === $timezone ? 'selected' : '' }}>
                                                                        {{ $timezone }}
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        @elseif($setting->key === 'locale')
                                                            <select class="form-select" 
                                                                    id="{{ $setting->key }}" 
                                                                    name="settings[{{ $setting->key }}]"
                                                                    @cannot('edit system configurations') disabled @endcannot>
                                                                <option value="en" {{ $setting->value === 'en' ? 'selected' : '' }}>English</option>
                                                                <option value="sw" {{ $setting->value === 'sw' ? 'selected' : '' }}>Swahili</option>
                                                                <option value="fr" {{ $setting->value === 'fr' ? 'selected' : '' }}>French</option>
                                                                <option value="es" {{ $setting->value === 'es' ? 'selected' : '' }}>Spanish</option>
                                                                <option value="ar" {{ $setting->value === 'ar' ? 'selected' : '' }}>Arabic</option>
                                                            </select>
                                                        @elseif($setting->key === 'currency')
                                                            <select class="form-select" 
                                                                    id="{{ $setting->key }}" 
                                                                    name="settings[{{ $setting->key }}]"
                                                                    @cannot('edit system configurations') disabled @endcannot>
                                                                <option value="TZS" {{ $setting->value === 'TZS' ? 'selected' : '' }}>TZS - Tanzania Shilling</option>
                                                                <option value="USD" {{ $setting->value === 'USD' ? 'selected' : '' }}>USD - US Dollar</option>
                                                                <option value="EUR" {{ $setting->value === 'EUR' ? 'selected' : '' }}>EUR - Euro</option>
                                                                <option value="GBP" {{ $setting->value === 'GBP' ? 'selected' : '' }}>GBP - British Pound</option>
                                                                <option value="JPY" {{ $setting->value === 'JPY' ? 'selected' : '' }}>JPY - Japanese Yen</option>
                                                                <option value="CAD" {{ $setting->value === 'CAD' ? 'selected' : '' }}>CAD - Canadian Dollar</option>
                                                                <option value="AUD" {{ $setting->value === 'AUD' ? 'selected' : '' }}>AUD - Australian Dollar</option>
                                                                <option value="CHF" {{ $setting->value === 'CHF' ? 'selected' : '' }}>CHF - Swiss Franc</option>
                                                                <option value="CNY" {{ $setting->value === 'CNY' ? 'selected' : '' }}>CNY - Chinese Yuan</option>
                                                                <option value="INR" {{ $setting->value === 'INR' ? 'selected' : '' }}>INR - Indian Rupee</option>
                                                                <option value="BRL" {{ $setting->value === 'BRL' ? 'selected' : '' }}>BRL - Brazilian Real</option>
                                                                <option value="KES" {{ $setting->value === 'KES' ? 'selected' : '' }}>KES - Kenyan Shilling</option>
                                                                <option value="UGX" {{ $setting->value === 'UGX' ? 'selected' : '' }}>UGX - Ugandan Shilling</option>
                                                                <option value="ZAR" {{ $setting->value === 'ZAR' ? 'selected' : '' }}>ZAR - South African Rand</option>
                                                            </select>
                                                        @elseif($setting->key === 'backup_frequency')
                                                            <select class="form-select" 
                                                                    id="{{ $setting->key }}" 
                                                                    name="settings[{{ $setting->key }}]"
                                                                    @cannot('edit system configurations') disabled @endcannot>
                                                                <option value="hourly" {{ $setting->value === 'hourly' ? 'selected' : '' }}>Hourly</option>
                                                                <option value="daily" {{ $setting->value === 'daily' ? 'selected' : '' }}>Daily</option>
                                                                <option value="weekly" {{ $setting->value === 'weekly' ? 'selected' : '' }}>Weekly</option>
                                                                <option value="monthly" {{ $setting->value === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                                            </select>
                                                        @elseif($setting->key === 'log_level')
                                                            <select class="form-select" 
                                                                    id="{{ $setting->key }}" 
                                                                    name="settings[{{ $setting->key }}]"
                                                                    @cannot('edit system configurations') disabled @endcannot>
                                                                <option value="emergency" {{ $setting->value === 'emergency' ? 'selected' : '' }}>Emergency</option>
                                                                <option value="alert" {{ $setting->value === 'alert' ? 'selected' : '' }}>Alert</option>
                                                                <option value="critical" {{ $setting->value === 'critical' ? 'selected' : '' }}>Critical</option>
                                                                <option value="error" {{ $setting->value === 'error' ? 'selected' : '' }}>Error</option>
                                                                <option value="warning" {{ $setting->value === 'warning' ? 'selected' : '' }}>Warning</option>
                                                                <option value="notice" {{ $setting->value === 'notice' ? 'selected' : '' }}>Notice</option>
                                                                <option value="info" {{ $setting->value === 'info' ? 'selected' : '' }}>Info</option>
                                                                <option value="debug" {{ $setting->value === 'debug' ? 'selected' : '' }}>Debug</option>
                                                            </select>
                                                        @elseif($setting->key === 'mail_encryption')
                                                            <select class="form-select" 
                                                                    id="{{ $setting->key }}" 
                                                                    name="settings[{{ $setting->key }}]"
                                                                    @cannot('edit system configurations') disabled @endcannot>
                                                                <option value="tls" {{ $setting->value === 'tls' ? 'selected' : '' }}>TLS</option>
                                                                <option value="ssl" {{ $setting->value === 'ssl' ? 'selected' : '' }}>SSL</option>
                                                                <option value="" {{ $setting->value === '' ? 'selected' : '' }}>None</option>
                                                            </select>
                                                        @elseif($setting->key === 'mail_from_address')
                                                            <div class="input-group">
                                                                <input type="email" 
                                                                       class="form-control" 
                                                                       id="{{ $setting->key }}" 
                                                                       name="settings[{{ $setting->key }}]" 
                                                                       value="{{ $setting->value }}" 
                                                                       placeholder="Enter email address"
                                                                       @cannot('edit system configurations') readonly @endcannot>
                                                                @can('edit system configurations')
                                                                <button type="button" class="btn btn-outline-primary" onclick="testEmailConfig()">
                                                                    <i class="bx bx-send me-1"></i> Test
                                                                </button>
                                                                @endcan
                                                            </div>
                                                        @else
                                                            <input type="text" 
                                                                   class="form-control" 
                                                                   id="{{ $setting->key }}" 
                                                                   name="settings[{{ $setting->key }}]" 
                                                                   value="{{ $setting->value }}" 
                                                                   placeholder="Enter {{ strtolower($setting->label) }}"
                                                                   @cannot('edit system configurations') readonly @endcannot>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @can('edit system configurations')
                            <div class="mt-4">
                                {!! form_submit_button(__('app.save_settings'), 'btn btn-primary', 'bx bx-save') !!}
                                <button type="reset" class="btn btn-secondary">
                                    <i class="bx bx-reset me-1"></i> {{ __('app.reset_form') }}
                                </button>
                            </div>
                            @endcan
                        @can('edit system configurations')
                        </form>
                        @else
                        </div>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reset Confirmation Modal -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset System Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reset all system settings to their default values?</p>
                <p class="text-warning"><i class="bx bx-warning me-1"></i> This action cannot be undone!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                @can('manage system configurations')
                <form action="{{ route('settings.system.reset') }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-warning" onclick="return confirmDelete(this.form, '{{ __('app.are_you_sure_reset_settings') }}')">{{ __('app.reset_to_defaults') }}</button>
                </form>
                @endcan
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // If URL hash matches a tab (e.g. #backup-tab), activate that tab on load
    var hash = window.location.hash;
    if (hash) {
        var tabTrigger = document.querySelector('[data-bs-target="' + hash.replace('-tab', '-content') + '"]');
        if (tabTrigger) {
            new bootstrap.Tab(tabTrigger).show();
        }
    }

    // ── SMS Reminder master toggle ──────────────────────────────────────────
    var masterToggle = document.getElementById('sms_reminder_enabled');
    var reminderKeys = [
        'sms_reminder_3_days_before',
        'sms_reminder_2_days_before',
        'sms_reminder_1_day_before',
        'sms_reminder_on_due_date',
    ];

    function applyReminderState(enabled) {
        reminderKeys.forEach(function (key) {
            var input   = document.getElementById(key);
            if (!input) return;
            var wrapper = input.closest('.col-md-6');          // the whole grid column

            if (enabled) {
                if (wrapper) wrapper.style.display = '';
                input.disabled = input.getAttribute('data-perm-disabled') === '1';
            } else {
                if (wrapper) wrapper.style.display = 'none';
                input.checked  = false;                        // visually uncheck
                input.disabled = true;                         // prevent form submission as '1'
            }
        });
    }

    if (masterToggle) {
        // Remember which inputs were already permission-disabled (read-only users)
        reminderKeys.forEach(function (key) {
            var input = document.getElementById(key);
            if (input && input.disabled) {
                input.setAttribute('data-perm-disabled', '1');
            }
        });

        // Apply initial state on page load
        applyReminderState(masterToggle.checked);

        // React to the master toggle changing
        masterToggle.addEventListener('change', function () {
            applyReminderState(this.checked);
        });
    }
    // ── END SMS Reminder master toggle ──────────────────────────────────────

    // Show password requirements for security settings
    showPasswordRequirements();
    
    // Auto-save functionality (optional)
    let autoSaveTimer;
    const form = document.querySelector('form');
    const inputs = form.querySelectorAll('input, select, textarea');
    
    inputs.forEach(input => {
        input.addEventListener('change', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                // Show auto-save indicator
                const saveBtn = form.querySelector('button[type="submit"]');
                const originalText = saveBtn.innerHTML;
                saveBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Auto-saving...';
                saveBtn.disabled = true;
                
                // Auto-save after 2 seconds of inactivity
                setTimeout(() => {
                    form.submit();
                }, 2000);
            }, 2000);
        });
    });
});

function showPasswordRequirements() {
    // Get current password settings
    const minLength = document.getElementById('password_min_length')?.value || 8;
    const requireSpecial = document.getElementById('password_require_special')?.checked || false;
    const requireNumbers = document.getElementById('password_require_numbers')?.checked || false;
    const requireUppercase = document.getElementById('password_require_uppercase')?.checked || false;
    
    // Create requirements text
    let requirements = [`Minimum ${minLength} characters`];
    if (requireUppercase) requirements.push('At least one uppercase letter');
    if (requireNumbers) requirements.push('At least one number');
    if (requireSpecial) requirements.push('At least one special character');
    
    // Show requirements in security tab
    const securityTab = document.getElementById('security-content');
    if (securityTab) {
        const requirementsDiv = securityTab.querySelector('.password-requirements');
        if (!requirementsDiv) {
            const div = document.createElement('div');
            div.className = 'alert alert-info password-requirements mt-3';
            div.innerHTML = '<strong>Current Password Requirements:</strong><br>' + requirements.join('<br>');
            securityTab.appendChild(div);
        } else {
            requirementsDiv.innerHTML = '<strong>Current Password Requirements:</strong><br>' + requirements.join('<br>');
        }
    }
}

function confirmReset() {
    var resetModal = new bootstrap.Modal(document.getElementById('resetModal'));
    resetModal.show();
}

// Test email configuration
function testEmailConfig() {
    const emailField = document.getElementById('mail_from_address');
    const email = emailField.value;
    
    if (!email) {
        alert('Please enter an email address first.');
        return;
    }
    
    // Show loading state
    const testBtn = event.target;
    const originalText = testBtn.innerHTML;
    testBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Testing...';
    testBtn.disabled = true;
    
    // Make AJAX request
    fetch('{{ route("settings.system.test-email") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({ email: email })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Email test successful!');
        } else {
            alert('Email test failed: ' + data.message);
        }
    })
    .catch(error => {
        alert('Email test failed: ' + error.message);
    })
    .finally(() => {
        testBtn.innerHTML = originalText;
        testBtn.disabled = false;
    });
}
</script>
@endpush 