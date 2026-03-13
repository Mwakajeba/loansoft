<?php

namespace App\Http\Controllers;

use App\Models\Complain;
use Illuminate\Http\Request;

class ComplaintsController extends Controller
{
    /**
     * List all complaints (Malalamiko) for the current branch/company.
     */
    public function index(Request $request)
    {
        $query = Complain::with(['category', 'customer', 'respondedBy'])
            ->orderBy('created_at', 'desc');

        if (auth()->user()->branch_id) {
            $query->where('branch_id', auth()->user()->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $complaints = $query->paginate(20)->withQueryString();

        return view('complaints.index', compact('complaints'));
    }

    /**
     * Show form to add/edit response for a complaint.
     */
    public function edit(Complain $complaint)
    {
        $this->authorizeBranch($complaint);
        $complaint->load(['category', 'customer', 'respondedBy']);
        return view('complaints.edit', compact('complaint'));
    }

    /**
     * Update complaint with response and optionally status.
     */
    public function update(Request $request, Complain $complaint)
    {
        $this->authorizeBranch($complaint);

        $validated = $request->validate([
            'response' => 'nullable|string|max:65535',
            'status'   => 'required|in:pending,resolved,closed',
        ]);

        $complaint->update([
            'response'      => $validated['response'] ? trim($validated['response']) : null,
            'status'        => $validated['status'],
            'responded_at'  => $validated['response'] ? now() : $complaint->responded_at,
            'responded_by'  => $validated['response'] ? auth()->id() : $complaint->responded_by,
        ]);

        return redirect()->route('complaints.index')
            ->with('success', 'Malalamiko limebadilishwa.');
    }

    private function authorizeBranch(Complain $complaint): void
    {
        $branchId = auth()->user()->branch_id;
        if ($branchId && (int) $complaint->branch_id !== (int) $branchId) {
            abort(403, 'Unauthorized.');
        }
    }
}
