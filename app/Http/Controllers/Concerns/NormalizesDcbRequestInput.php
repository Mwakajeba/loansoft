<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait NormalizesDcbRequestInput
{
    /**
     * Map prefixed DCB form fields (dcb_repay_*, dcb_settle_*, dcb_disburse_*) to standard keys.
     */
    protected function normalizeDcbRequestInput(Request $request, string ...$prefixes): void
    {
        $map = [
            'dcb_institution_code' => 'institution_code',
            'dcb_destination_account' => 'destination_account',
            'dcb_msisdn' => 'msisdn',
            'dcb_beneficiary_name' => 'beneficiary_name',
            'dcb_control_no' => 'control_no', // dcb_repay_control_no → dcb_control_no
        ];

        foreach ($map as $target => $suffix) {
            if ($request->filled($target)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                $prefixedKey = $prefix . '_' . $suffix;
                if ($request->filled($prefixedKey)) {
                    $request->merge([$target => $request->input($prefixedKey)]);
                    break;
                }
            }
        }
    }
}
