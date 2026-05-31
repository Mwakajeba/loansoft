@if(filter_var(config('services.dcb.enabled'), FILTER_VALIDATE_BOOLEAN))
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';
    const institutionsUrl = @json(route('settings.dcb.financial-institutions'));
    const lookupUrl = @json(route('settings.dcb.account-lookup'));

    function fieldsRoot(prefix) {
        return document.querySelector('.dcb-payment-fields[data-dcb-prefix="' + prefix + '"]');
    }

    function fieldEl(prefix, suffix) {
        const root = fieldsRoot(prefix);
        return root ? root.querySelector('[id="' + prefix + '_' + suffix + '"]') : null;
    }

    window.loadDcbInstitutions = function (prefix) {
        const sel = fieldEl(prefix, 'institution_code');
        if (!sel) return Promise.reject(new Error('Institution select not found for ' + prefix));

        const btn = fieldsRoot(prefix)?.querySelector('.dcb-load-institutions');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Loading…';
        }

        return fetch(institutionsUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const current = sel.value;
                sel.innerHTML = '<option value="">— Select institution —</option>';
                const list = data.financial_institutions || data.fsps || [];
                list.forEach(function (fsp) {
                    const code = fsp.institutionCode || fsp.institution_code || fsp.code || '';
                    const name = fsp.institutionName || fsp.name || code;
                    if (!code) return;
                    const opt = document.createElement('option');
                    opt.value = code;
                    opt.textContent = name + ' (' + code + ')';
                    if (code === current) opt.selected = true;
                    sel.appendChild(opt);
                });
                if (!list.length && data.message) {
                    console.warn('DCB institutions:', data.message);
                }
                return data;
            })
            .catch(function (e) {
                alert('Failed to load financial institutions: ' + e.message);
                throw e;
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Refresh FSP list';
                }
            });
    };

    document.querySelectorAll('.dcb-load-institutions').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const prefix = btn.getAttribute('data-prefix');
            if (prefix) loadDcbInstitutions(prefix);
        });
    });

    document.querySelectorAll('.dcb-lookup').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const prefix = btn.getAttribute('data-prefix');
            const account = fieldEl(prefix, 'destination_account')?.value;
            const code = fieldEl(prefix, 'institution_code')?.value;
            const resultEl = fieldEl(prefix, 'lookup_result');

            if (!account || !code) {
                alert('Select institution and enter account/MSISDN first.');
                return;
            }

            fetch(lookupUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ account_no: account, institution_code: code, normalize: true }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (resultEl) {
                        resultEl.style.display = 'block';
                        resultEl.textContent = data.success
                            ? 'Account verified.'
                            : (data.message || JSON.stringify(data.data || data));
                    }
                    if (data.success && data.data) {
                        const name = data.data.accountName || data.data.beneficiary_name || data.data.name;
                        const ben = fieldEl(prefix, 'beneficiary_name');
                        if (name && ben) ben.value = name;
                    }
                })
                .catch(function (e) {
                    if (resultEl) {
                        resultEl.style.display = 'block';
                        resultEl.textContent = e.message;
                    }
                });
        });
    });
})();
</script>
@endif
