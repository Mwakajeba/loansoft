<?php

namespace App\Http\Controllers;

use App\Models\DcbTransaction;
use App\Services\DcbGatewayService;
use App\Services\DcbPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
class DcbGatewayController extends Controller
{
    public function __construct(
        private readonly DcbGatewayService $gateway,
        private readonly DcbPaymentService $payments
    ) {}

    /**
     * DCB asynchronous transfer result callback (public, CSRF-exempt).
     */
    public function callback(Request $request): JsonResponse
    {
        $secret = config('services.dcb.callback_secret');
        if ($secret) {
            $provided = $request->header('X-DCB-Callback-Secret')
                ?? $request->input('callback_secret');

            if (!hash_equals($secret, (string) $provided)) {
                Log::warning('DCB callback rejected: invalid secret');

                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
        }

        $payload = $request->all();
        Log::info('DCB callback received', [
            'client_reference' => $payload['client_reference'] ?? $payload['clientReference'] ?? null,
        ]);

        $result = $this->payments->handleCallback($payload);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function financialInstitutions(): JsonResponse
    {
        if (!config('services.dcb.enabled')) {
            return response()->json(['success' => false, 'message' => 'DCB payments are disabled.'], 503);
        }

        $result = $this->gateway->getFinancialInstitutions();
        $institutions = $result['financial_institutions'] ?? $result['fsps'] ?? [];

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'financial_institutions' => $institutions,
            'message' => $result['message'] ?? null,
        ], ($result['success'] ?? false) ? 200 : 502);
    }

    public function accountLookup(Request $request): JsonResponse
    {
        if (!config('services.dcb.enabled')) {
            return response()->json(['success' => false, 'message' => 'DCB payments are disabled.'], 503);
        }

        $validated = $request->validate([
            'account_no' => 'required|string|max:64',
            'institution_code' => 'required|string|max:64',
            'normalize' => 'nullable|boolean',
            'strip_leading_zeros' => 'nullable|boolean',
        ]);

        if (($validated['normalize'] ?? false) && ($validated['strip_leading_zeros'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot set both normalize and strip_leading_zeros.',
            ], 422);
        }

        $result = $this->gateway->accountLookup($validated);

        return response()->json($result, ($result['http_status'] ?? 200) >= 400 ? ($result['http_status'] ?? 502) : 200);
    }

    public function transfer(Request $request): JsonResponse
    {
        if (!config('services.dcb.enabled')) {
            return response()->json(['success' => false, 'message' => 'DCB payments are disabled.'], 503);
        }

        $validated = $request->validate([
            'destination_account' => 'required|string|max:64',
            'institution_code' => 'required|string|max:64',
            'institution_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:1',
            'beneficiary_name' => 'required|string|max:120',
            'msisdn' => 'required|string|max:20',
            'sender_name' => 'nullable|string|max:120',
            'purpose' => 'nullable|string|max:32',
            'normalize_destination' => 'nullable|boolean',
            'strip_destination_leading_zeros' => 'nullable|boolean',
            'client_reference' => 'nullable|string|max:32|regex:/^[A-Za-z0-9_-]+$/',
            'reference_type' => 'nullable|string|max:64',
            'reference_id' => 'nullable|integer',
        ]);

        if (($validated['normalize_destination'] ?? false) && ($validated['strip_destination_leading_zeros'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot set both normalize_destination and strip_destination_leading_zeros.',
            ], 422);
        }

        $result = $this->payments->initiateTransfer(
            $validated,
            $validated['reference_type'] ?? null,
            $validated['reference_id'] ?? null
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function transactions(Request $request): JsonResponse
    {
        $query = DcbTransaction::query()
            ->when(current_company_id(), fn ($q) => $q->where('company_id', current_company_id()))
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->paginate(20);

        return response()->json($transactions);
    }

    public function disburseLoan(Request $request, int $loanId): JsonResponse
    {
        if (!config('services.dcb.enabled')) {
            return response()->json(['success' => false, 'message' => 'DCB payments are disabled.'], 503);
        }

        $loan = \App\Models\Loan::with('customer')->findOrFail($loanId);

        $validated = $request->validate([
            'destination_account' => 'nullable|string|max:64',
            'institution_code' => 'required|string|max:64',
            'institution_name' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:1',
            'beneficiary_name' => 'nullable|string|max:120',
            'msisdn' => 'nullable|string|max:20',
            'normalize_destination' => 'nullable|boolean',
        ]);

        $result = $this->payments->disburseLoan($loan, $validated);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }
}
