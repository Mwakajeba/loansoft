<?php

namespace App\Http\Controllers;

use App\Exports\GenericArrayExport;
use App\Models\Group;
use App\Support\Loans\GroupLoansRowBuilder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Vinkla\Hashids\Facades\Hashids;

class GroupLoanAjaxController extends Controller
{
    public function index(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $data = GroupLoansRowBuilder::buildRows($group);

        return response()->json(['data' => $data]);
    }

    public function export(Request $request, $encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('groups.index')->withErrors(['Group not found.']);
        }

        $group = Group::findOrFail($decoded[0]);
        $rows = GroupLoansRowBuilder::buildRows($group, true);

        $headings = [
            'loan_no',
            'customer_no',
            'customer_name',
            'amount_with_interest',
            'total_paid',
            'outstanding_balance',
            'disbursed_on',
            'expiry_date',
            'status',
        ];

        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $group->name);
        $filename = 'group_loans_' . $safeName . '_' . date('Y-m-d') . '.xlsx';

        return Excel::download(new GenericArrayExport($rows, $headings), $filename);
    }
}
