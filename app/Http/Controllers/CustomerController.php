<?php




namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Region;
use App\Models\District;
use App\Models\User;
use App\Models\CashCollateralType;
use App\Models\Filetype;
use App\Services\LoanPenaltyService;
use App\Exports\CustomerBulkUploadSampleExport;
use App\Exports\CustomerBulkUploadFailedExport;
use App\Jobs\BulkCustomerUploadJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

set_time_limit(0);              // no limit for this request
ini_set('max_execution_time', 0);

class CustomerController extends Controller
{
    /**
     * Format phone number to standard format - always starts with 255
     * - If starts with 0, remove 0 and add 255
     * - If starts with +255, remove +
     * - If doesn't start with 255, add 255
     */
    private function formatPhoneNumber($phoneNumber)
    {
        if (empty($phoneNumber)) {
            return $phoneNumber;
        }

        // Remove any spaces, dashes, or special characters except +
        $phoneNumber = preg_replace("/[^0-9+]/", "", $phoneNumber);

        // If starts with 0, remove 0 and add 255
        if (substr($phoneNumber, 0, 1) === "0") {
            return "255" . substr($phoneNumber, 1);
        }

        // If starts with +255, remove +
        if (substr($phoneNumber, 0, 4) === "+255") {
            return substr($phoneNumber, 1);
        }

        // If doesn't start with 255, add 255
        if (substr($phoneNumber, 0, 3) !== "255") {
            return "255" . $phoneNumber;
        }

        // Return as is if already in correct format
        return $phoneNumber;
    }
    
    /**
     * Validate National ID and extract information
     */
    private function validateNationalId($nationalId, $dob, $sex)
    {
        // Format: YYYYMMDD-XXXXX-XXXXX-XX
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{5})-(\d{5})-(\d{2})$/', $nationalId, $matches)) {
            return ['valid' => false, 'message' => 'Invalid National ID format'];
        }
        
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        $lastTwoDigits = $matches[6];
        
        // Validate date
        if (!checkdate($month, $day, $year)) {
            return ['valid' => false, 'message' => 'Invalid date in National ID'];
        }
        
        // Extract date from National ID
        $idDate = \Carbon\Carbon::create($year, $month, $day);
        
        // Compare with entered DOB
        $enteredDob = \Carbon\Carbon::parse($dob);
        if (!$idDate->isSameDay($enteredDob)) {
            return ['valid' => false, 'message' => 'Date of Birth does not match National ID date'];
        }
        
        // Validate age (must be 18 or older)
        $age = $idDate->age;
        if ($age < 18) {
            return ['valid' => false, 'message' => 'Age from National ID must be 18 years or older'];
        }
        
        // Validate sex - second digit from last (first digit of last two digits)
        $secondFromLast = (int)substr($lastTwoDigits, 0, 1);
        $expectedSexCode = $sex === 'M' ? 2 : 1;
        
        if ($secondFromLast !== $expectedSexCode) {
            $expectedSex = $sex === 'M' ? 'Male' : 'Female';
            $actualSex = $secondFromLast === 2 ? 'Male' : 'Female';
            return ['valid' => false, 'message' => "Sex does not match National ID. National ID indicates {$actualSex}, but you selected {$expectedSex}"];
        }
        
        return ['valid' => true];
    }

    // Display all customers
    public function index()
    {
        $branchId = auth()->user()->branch_id;
        $borrowerCount = Customer::where('category', 'Borrower')->where('branch_id', $branchId)->count();
        $guarantorCount = Customer::where('category', 'Guarantor')->where('branch_id', $branchId)->count();
        $customerCount = Customer::where('branch_id', $branchId)->count();

        return view('customers.index', compact('borrowerCount', 'guarantorCount', 'customerCount'));
    }

    // Ajax endpoint for DataTables
    public function getCustomersData(Request $request)
    {
        if ($request->ajax()) {
            $branchId = auth()->user()->branch_id;

            $customers = Customer::with(['branch', 'company', 'user', 'region', 'district'])
                ->where('branch_id', $branchId)
                ->select('customers.*');

            return DataTables::eloquent($customers)
                ->addColumn('avatar_name', function ($customer) {
                    $isGuarantor = isset($customer->category) && strtolower($customer->category) === 'guarantor';
                    $avatarClass = $isGuarantor ? 'bg-success' : 'bg-primary';
                    $initial = strtoupper(substr($customer->name, 0, 1));

                    return '<div class="d-flex align-items-center">
                                <div class="avatar avatar-sm ' . $avatarClass . ' rounded-circle me-2 d-flex align-items-center justify-content-center shadow" style="width:36px; height:36px;">
                                    <span class="avatar-title text-white fw-bold" style="font-size:1.25rem;">' . $initial . '</span>
                                </div>
                                <div>
                                    <div class="fw-bold">' . e($customer->name) . '</div>
                                </div>
                            </div>';
                })
                ->addColumn('region_name', function ($customer) {
                    return $customer->region->name ?? '';
                })
                ->addColumn('district_name', function ($customer) {
                    return $customer->district->name ?? '';
                })
                ->addColumn('branch_name', function ($customer) {
                    return optional($customer->branch)->name ?? '';
                })
                ->addColumn('actions', function ($customer) {
                    $actions = '';
                    $encodedId = \Vinkla\Hashids\Facades\Hashids::encode($customer->id);

                    // View action
                    if (auth()->user()->can('view customer profile')) {
                        $actions .= '<a href="' . route('customers.show', $encodedId) . '" class="btn btn-sm btn-outline-info me-1" title="View"><i class="bx bx-show"></i> Show</a>';
                    }

                    // Edit action
                    if (auth()->user()->can('edit customer')) {
                        $actions .= '<a href="' . route('customers.edit', $encodedId) . '" class="btn btn-sm btn-outline-primary me-1" title="Edit"><i class="bx bx-edit"></i> Edit</a>';
                    }

                    // Delete action
                    if (auth()->user()->can('delete customer')) {
                        $actions .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $encodedId . '" data-name="' . e($customer->name) . '" title="Delete"><i class="bx bx-trash"></i> Delete</button>';
                    }

                    return '<div class="text-center">' . $actions . '</div>';
                })
                ->rawColumns(['avatar_name', 'actions'])
                ->make(true);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }



    /////////DISPLAY ALL CUSTOMER WITH PENALTY AMOUNT  FOR THEIR LAON ///////
    public function penaltList()
    {
        $customerPenalties = LoanPenaltyService::getCustomerPenaltyBalances();
        $penaltyBalance = LoanPenaltyService::getTotalPenaltyBalance();
        return view('customers.penalty', compact('customerPenalties', 'penaltyBalance'));
    }

    // Show form to create a new customer
    public function create()
    {
        $branchId = auth()->user()->branch_id;
        $loanOfficers = User::where('branch_id', $branchId)->excludeSuperAdmin()->get();
        $filetypes = Filetype::orderBy('name')->get();
        $collateralTypes = CashCollateralType::where('is_active', 1)->get(); // active types only
        $branches = Branch::all();
        $companies = Company::all();
        $registrars = User::excludeSuperAdmin()->get();
        $regions = Region::all();
        $groups = \App\Models\Group::where('branch_id', $branchId)->where('id', '!=', 1)->get();

        $customer = null;
        return view('customers.create', compact('branches', 'companies', 'registrars', 'regions', 'loanOfficers', 'collateralTypes', 'filetypes', 'groups', 'customer'));
    }

    // Store a new customer
    public function store(Request $request)
    {
        // Basic validation rules
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'phone1' => 'required|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'dob' => ['required', 'date', function ($attribute, $value, $fail) {
                $dob = \Carbon\Carbon::parse($value);
                $age = $dob->age;
                if ($age < 18) {
                    $fail('Age must be 18 years or older.');
                }
            }],
            'sex' => 'required|in:M,F',
            'region_id' => 'required|exists:regions,id',
            'district_id' => 'required|exists:districts,id',
            'work' => 'nullable|string|max:255',
            'workAddress' => 'nullable|string|max:500',
            'idType' => 'nullable|string|max:100',
            'idNumber' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) use ($request) {
                if ($request->idType === 'National ID' && $value) {
                    $validation = $this->validateNationalId($value, $request->dob, $request->sex);
                    if (!$validation['valid']) {
                        $fail($validation['message']);
                    }
                }
            }],
            'relation' => 'nullable|string|max:255',
            'category' => 'required|in:Guarantor,Borrower',
            'group_id' => 'nullable|exists:groups,id',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'loan_officer_ids' => 'nullable|array',
            'loan_officer_ids.*' => 'exists:users,id',
            'has_cash_collateral' => 'nullable|boolean',
            'collateral_type_id' => 'nullable|exists:cash_collateral_types,id',

            // Dynamic filetypes + documents
            'filetypes' => 'nullable|array',
            'filetypes.*' => 'exists:filetypes,id',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ];

        $validated = $request->validate($rules);

        // Prepare customer data
        $data = $request->except(['customerNo', 'loan_officer_ids', 'collateral_type_id', 'filetypes', 'documents', 'group_id']);
        // Format phone numbers
        $data["phone1"] = $this->formatPhoneNumber($data["phone1"]);
        if (!empty($data["phone2"])) {
            $data["phone2"] = $this->formatPhoneNumber($data["phone2"]);
        }
        $data['category'] = $request->category;
        $password = '1234567890';
        $date = now()->toDateString();

        $data['customerNo'] = 100000 + (\App\Models\Customer::max('id') ?? 0) + 1;
        $data['password'] = Hash::make($password);
        $data['branch_id'] = auth()->user()->branch_id;
        $data['company_id'] = auth()->user()->company_id;
        $data['registrar'] = auth()->id();
        $data['dateRegistered'] = $date;
        $data['has_cash_collateral'] = $request->has('has_cash_collateral');

        // Upload photo
        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('photos', 'public');
        }

        // Upload document
        if ($request->hasFile('document')) {
            $data['document'] = $request->file('document')->store('documents', 'public');
        }

        DB::beginTransaction();
        try {
            $customer = \App\Models\Customer::create($data);

            // Save group membership - check if customer is already in a group first
            $existingMembership = DB::table('group_members')->where('customer_id', $customer->id)->first();

            if (!$existingMembership) {
                if ($request->filled('group_id')) {
                    DB::table('group_members')->insert([
                        'group_id' => $request->group_id,
                        'customer_id' => $customer->id,
                        'status' => 'active',
                        'joined_date' => now()->toDateString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('group_members')->insert([
                        'group_id' => 1,
                        'customer_id' => $customer->id,
                        'status' => 'active',
                        'joined_date' => now()->toDateString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Attach loan officers
            if ($request->has('loan_officer_ids')) {
                foreach ($request->loan_officer_ids as $officerId) {
                    DB::table('customer_officer')->insert([
                        'customer_id' => $customer->id,
                        'officer_id' => $officerId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Add cash collateral
            if ($request->has('has_cash_collateral') && $request->input('collateral_type_id')) {
                \App\Models\CashCollateral::create([
                    'customer_id' => $customer->id,
                    'type_id' => $request->input('collateral_type_id'),
                    'amount' => 0,
                    'branch_id' => auth()->user()->branch_id,
                    'company_id' => auth()->user()->company_id,
                ]);
            }

            // Save uploaded filetypes + documents
            if ($request->has('filetypes') && $request->hasFile('documents')) {
                $filetypes = $request->input('filetypes');
                $documents = $request->file('documents');

                foreach ($filetypes as $index => $filetypeId) {
                    if (isset($documents[$index])) {
                        $file = $documents[$index];
                        $path = $file->store('documents', 'public');

                        DB::table('customer_file_types')->insert([
                            'customer_id' => $customer->id,
                            'filetype_id' => $filetypeId,
                            'document_path' => $path,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();
            return redirect()->route('customers.index')->with('success', 'Customer created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to create customer: ' . $e->getMessage());
        }
    }


    // Display one customer
    public function show($encodedId)
    {
        $id = Hashids::decode($encodedId)[0] ?? null;

        if (!$id) {
            abort(404);
        }

        $customer = Customer::with('collaterals.type', 'loans', 'loanOfficers', 'filetypes')->findOrFail($id);

        return view('customers.show', compact('customer'));
    }

    // Show form to edit a customer
    public function edit($encodedId)
    {
        $id = \Vinkla\Hashids\Facades\Hashids::decode($encodedId)[0] ?? null;
        if (!$id) {
            abort(404);
        }
        $customer = Customer::findOrFail($id);
        $branchId = auth()->user()->branch_id;
        $loanOfficers = User::where('branch_id', $branchId)->excludeSuperAdmin()->get();
        $collateralTypes = \App\Models\CashCollateralType::where('is_active', 1)->get();
        $branches = \App\Models\Branch::all();
        $companies = \App\Models\Company::all();
        $registrars = \App\Models\User::excludeSuperAdmin()->get();
        $regions = \App\Models\Region::all();
        $filetypes = \App\Models\Filetype::orderBy('name')->get();
        $groups = \App\Models\Group::where('branch_id', $branchId)->get();
        $customer->load('loanOfficers', 'filetypes');
        return view('customers.edit', compact('branches', 'companies', 'registrars', 'regions', 'loanOfficers', 'collateralTypes', 'customer', 'filetypes', 'groups'));
    }

    // Update customer data
    public function update(Request $request, Customer $customer)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000', // Added description validation
            'phone1' => 'required|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'dob' => ['required', 'date', function ($attribute, $value, $fail) {
                $dob = \Carbon\Carbon::parse($value);
                $age = $dob->age;
                if ($age < 18) {
                    $fail('Age must be 18 years or older.');
                }
            }],
            'sex' => 'required|in:M,F',
            'region_id' => 'required|exists:regions,id',
            'district_id' => 'required|exists:districts,id',
            'work' => 'nullable|string|max:255',
            'workAddress' => 'nullable|string|max:500',
            'idType' => 'nullable|string|max:100',
            'idNumber' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) use ($request) {
                if ($request->idType === 'National ID' && $value) {
                    $validation = $this->validateNationalId($value, $request->dob, $request->sex);
                    if (!$validation['valid']) {
                        $fail($validation['message']);
                    }
                }
            }],
            'relation' => 'nullable|string|max:255',
            'category' => 'required|in:Guarantor,Borrower',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'password' => 'nullable|min:6',
            'loan_officer_ids' => 'nullable|array',
            'loan_officer_ids.*' => 'exists:users,id',
            'has_cash_collateral' => 'nullable|boolean',
            'collateral_type_id' => 'nullable|exists:cash_collateral_types,id', // Made optional

            'filetypes' => 'nullable|array',
            'filetypes.*' => 'exists:filetypes,id',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
        ]);

        $data = $request->except(['customerNo', 'loan_officer_ids', 'collateral_type_id']);
        // Format phone numbers
        $data["phone1"] = $this->formatPhoneNumber($data["phone1"]);
        if (!empty($data["phone2"])) {
            $data["phone2"] = $this->formatPhoneNumber($data["phone2"]);
        }
        $data['category'] = $request->category;

        // Set these from logged-in user
        $data['branch_id'] = auth()->user()->branch_id;
        $data['company_id'] = auth()->user()->company_id;
        $data['registrar'] = auth()->id();
        $data['has_cash_collateral'] = $request->has('has_cash_collateral') ? true : false; // Set boolean value

        // Hash password only if provided
        if (!empty($request->password)) {
            $data['password'] = Hash::make($request->password);
        } else {
            unset($data['password']); // Don't overwrite with null
        }

        // Photo upload
        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('photos', 'public');
        }

        // Document upload
        if ($request->hasFile('document')) {
            $data['document'] = $request->file('document')->store('documents', 'public');
        }

        DB::beginTransaction();
        try {
            $customer->update($data);

            // Sync group membership
            DB::table('group_members')->where('customer_id', $customer->id)->delete();
            // Save group membership
            if ($request->filled('group_id')) {
                DB::table('group_members')->insert([
                    'group_id' => $request->group_id,
                    'customer_id' => $customer->id,
                    'status' => 'active',
                    'joined_date' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('group_members')->insert([
                    'group_id' => 1,
                    'customer_id' => $customer->id,
                    'status' => 'active',
                    'joined_date' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Sync loan officers
            if ($request->has('loan_officer_ids')) {
                // Delete previous ones
                DB::table('customer_officer')->where('customer_id', $customer->id)->delete();

                // Insert new ones
                if (!empty($request->loan_officer_ids)) {
                    foreach ($request->loan_officer_ids as $officerId) {
                        DB::table('customer_officer')->insert([
                            'customer_id' => $customer->id,
                            'officer_id' => $officerId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            } else {
                // If none selected, remove all previous
                DB::table('customer_officer')->where('customer_id', $customer->id)->delete();
            }

            // Handle cash collateral
            if ($request->has('has_cash_collateral') && $request->has('collateral_type_id') && $request->collateral_type_id) {
                // Check if collateral already exists
                $existingCollateral = \App\Models\CashCollateral::where('customer_id', $customer->id)->first();

                if ($existingCollateral) {
                    $existingCollateral->update([
                        'type_id' => $request->input('collateral_type_id'),
                    ]);
                } else {
                    \App\Models\CashCollateral::create([
                        'customer_id' => $customer->id,
                        'type_id' => $request->input('collateral_type_id'),
                        'amount' => 0,
                        'branch_id' => auth()->user()->branch_id,
                        'company_id' => auth()->user()->company_id,
                    ]);
                }
            } else {
                // If not checked, remove existing collateral
                \App\Models\CashCollateral::where('customer_id', $customer->id)->delete();
            }

            // Sync File Types and Uploaded Documents
            if ($request->has('filetypes') && $request->hasFile('documents')) {
                $filetypes = $request->filetypes;
                $documents = $request->file('documents');

                // Delete existing filetype entries to prevent duplicates
                DB::table('customer_file_types')->where('customer_id', $customer->id)->delete();

                foreach ($filetypes as $index => $filetypeId) {
                    if (isset($documents[$index])) {
                        $file = $documents[$index];
                        $path = $file->store('documents', 'public');

                        DB::table('customer_file_types')->insert([
                            'customer_id' => $customer->id,
                            'filetype_id' => $filetypeId,
                            'document_path' => $path,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();
            return redirect()->route('customers.index')->with('success', 'Customer updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update customer: ' . $e->getMessage());
        }
    }

    // Delete customer
    public function destroy($id)
    {
        $decoded = Hashids::decode($id)[0] ?? null;
        try {
            $customer = Customer::findOrFail($decoded);

            // Check for existing loans, cash collaterals, or GL transactions

            //check if member is in any group then he need to delete that mmeber from that group
            $existingMembership = DB::table('group_members')->where('customer_id', $customer->id)->where('group_id', '!=', 1)->first();
            if ($existingMembership) {
                return redirect()->route('customers.index')->with('error', 'Customer is a member of a group. Please remove them from the group first.');
            }
            $hasLoans = $customer->loans()->exists();
            $hasCollaterals = $customer->collaterals()->exists();
            $hasGLTransactions = \DB::table('gl_transactions')->where('customer_id', $customer->id)->exists();

            if ($hasLoans || $hasCollaterals || $hasGLTransactions) {
                $msg = 'Cannot delete customer: ';
                if ($hasLoans) {
                    $msg .= 'Customer has existing loans. ';
                }
                if ($hasCollaterals) {
                    $msg .= 'Customer has cash collaterals. ';
                }
                if ($hasGLTransactions) {
                    $msg .= 'Customer has transactions.';
                }
                return redirect()->route('customers.index')->with('error', $msg);
            }

            $customer->delete();

            return redirect()->route('customers.index')->with('success', 'Customer deleted successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete customer: ' . $e->getMessage());
        }
    }

    // Show bulk upload form
    public function bulkUpload()
    {
        $collateralTypes = CashCollateralType::where('is_active', 1)->get();
        return view('customers.bulk-upload', compact('collateralTypes'));
    }

    // Process bulk upload
    public function bulkUploadStore(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240', // 10MB max, includes Excel
            'has_cash_collateral' => 'nullable|boolean',
            'collateral_type_id' => 'nullable|exists:cash_collateral_types,id',
        ]);

        if ($request->has('has_cash_collateral') && !$request->collateral_type_id) {
            return back()->withErrors(['collateral_type_id' => 'Please select a collateral type when applying cash collateral.']);
        }

        try {
            $file = $request->file('csv_file');
            $extension = $file->getClientOriginalExtension();
            $path = $file->getRealPath();
            $data = [];
            $header = [];

            // Read file based on extension
            if (in_array(strtolower($extension), ['xlsx', 'xls'])) {
                // Read Excel file
                $spreadsheet = IOFactory::load($path);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                if (empty($rows)) {
                    return back()->withErrors(['csv_file' => 'Excel file is empty.']);
                }
                
                // Find header row (skip instruction rows)
                $headerRowIndex = 0;
                $header = [];
                
                // Look for header row - it should contain at least 'name' and 'phone1'
                // Check more rows to handle instruction rows
                for ($i = 0; $i < min(20, count($rows)); $i++) {
                    $potentialHeader = array_map(function($cell) {
                        $value = is_null($cell) ? '' : (string)$cell;
                        return strtolower(trim($value));
                    }, $rows[$i]);
                    
                    // Skip rows that are clearly not headers (empty, instructions, etc.)
                    $nonEmptyCells = array_filter($potentialHeader, function($val) {
                        return !empty($val) && 
                               !preg_match('/^(instruction|note|delete|fill|use|template|customer bulk)/i', $val);
                    });
                    
                    if (count($nonEmptyCells) < 4) {
                        continue; // Skip rows with too few columns
                    }
                    
                    // Normalize column names (remove spaces, handle variations)
                    $normalizedHeader = array_map(function($col) {
                        $col = strtolower(trim($col));
                        $col = preg_replace('/\s+/', '', $col); // Remove spaces
                        $col = preg_replace('/[^a-z0-9_]/', '', $col); // Remove special chars
                        
                        // Handle common variations
                        $variations = [
                            'name' => ['name', 'fullname', 'full_name', 'customername', 'customer_name', 'fullname'],
                            'phone1' => ['phone1', 'phone', 'phone_1', 'phonenumber', 'phone_number', 'mobile', 'mobile1', 'phonenumber1'],
                            'phone2' => ['phone2', 'phone_2', 'mobile2', 'alternatephone', 'alternate_phone', 'phonenumber2'],
                            'dob' => ['dob', 'dateofbirth', 'date_of_birth', 'birthdate', 'birth_date', 'dateofbirth'],
                            'sex' => ['sex', 'gender'],
                            'region_id' => ['region_id', 'regionid', 'region', 'regionname', 'regionname'],
                            'district_id' => ['district_id', 'districtid', 'district', 'districtname', 'districtname'],
                            'work' => ['work', 'occupation', 'job'],
                            'workaddress' => ['workaddress', 'work_address', 'workaddress'],
                            'idtype' => ['idtype', 'id_type', 'identificationtype'],
                            'idnumber' => ['idnumber', 'id_number', 'identificationnumber'],
                            'relation' => ['relation', 'relationship'],
                            'description' => ['description', 'desc', 'notes'],
                            'category' => ['category', 'role', 'borrower_guarantor', 'customer_type', 'customertype'],
                        ];
                        
                        foreach ($variations as $standard => $aliases) {
                            if (in_array($col, $aliases)) {
                                return $standard;
                            }
                        }
                        return $col;
                    }, $potentialHeader);
                    
                    // Check if this row contains required columns (name and phone1)
                    $hasName = in_array('name', $normalizedHeader);
                    $hasPhone1 = in_array('phone1', $normalizedHeader);
                    
                    if ($hasName && $hasPhone1) {
                        $header = $normalizedHeader;
                        $headerRowIndex = $i;
                        break;
                    }
                }
                
                if (empty($header)) {
                    return back()->withErrors(['csv_file' => 'Could not find header row. Please ensure the file has columns: name, phone1, dob, sex']);
                }
                
                // Remove rows before header and the header row itself
                $rows = array_slice($rows, $headerRowIndex + 1);
                
                // Convert rows to associative arrays
                foreach ($rows as $row) {
                    $rowData = [];
                    foreach ($header as $index => $headerName) {
                        $rowData[$headerName] = trim($row[$index] ?? '');
                    }
                    if (!empty(array_filter($rowData, function($val) { return $val !== ''; }))) { // Skip empty rows
                        $data[] = $rowData;
                    }
                }
            } else {
                // Read CSV file
                $csvData = array_map('str_getcsv', file($path));
                
                // Find header row
                $headerRowIndex = 0;
                $header = [];
                
                for ($i = 0; $i < min(10, count($csvData)); $i++) {
                    $potentialHeader = array_map(function($cell) {
                        return strtolower(trim($cell ?? ''));
                    }, $csvData[$i]);
                    
                    // Normalize column names
                    $normalizedHeader = array_map(function($col) {
                        $col = strtolower(trim($col));
                        $col = preg_replace('/\s+/', '', $col);
                        $variations = [
                            'name' => ['name', 'fullname', 'full_name', 'customername', 'customer_name'],
                            'phone1' => ['phone1', 'phone', 'phone_1', 'phonenumber', 'phone_number', 'mobile', 'mobile1'],
                            'phone2' => ['phone2', 'phone_2', 'mobile2', 'alternatephone', 'alternate_phone'],
                            'dob' => ['dob', 'dateofbirth', 'date_of_birth', 'birthdate', 'birth_date'],
                            'sex' => ['sex', 'gender'],
                            'region_id' => ['region_id', 'regionid', 'region', 'regionname'],
                            'district_id' => ['district_id', 'districtid', 'district', 'districtname'],
                            'work' => ['work', 'occupation', 'job'],
                            'workaddress' => ['workaddress', 'work_address', 'workaddress'],
                            'idtype' => ['idtype', 'id_type', 'identificationtype'],
                            'idnumber' => ['idnumber', 'id_number', 'identificationnumber'],
                            'relation' => ['relation', 'relationship'],
                            'description' => ['description', 'desc', 'notes'],
                            'category' => ['category', 'role', 'borrower_guarantor', 'customer_type', 'customertype'],
                        ];
                        
                        foreach ($variations as $standard => $aliases) {
                            if (in_array($col, $aliases)) {
                                return $standard;
                            }
                        }
                        return $col;
                    }, $potentialHeader);
                    
                    if (in_array('name', $normalizedHeader) && in_array('phone1', $normalizedHeader)) {
                        $header = $normalizedHeader;
                        $headerRowIndex = $i;
                        break;
                    }
                }
                
                if (empty($header)) {
                    return back()->withErrors(['csv_file' => 'Could not find header row. Please ensure the file has columns: name, phone1, dob, sex']);
                }
                
                // Remove rows before header and the header row itself
                $csvData = array_slice($csvData, $headerRowIndex + 1);
                
                // Convert rows to associative arrays
                foreach ($csvData as $row) {
                    if (count($row) >= count($header)) {
                        $rowData = [];
                        foreach ($header as $index => $headerName) {
                            $rowData[$headerName] = trim($row[$index] ?? '');
                        }
                        if (!empty(array_filter($rowData, function($val) { return $val !== ''; }))) {
                            $data[] = $rowData;
                        }
                    }
                }
            }

            // Validate file structure
            $requiredColumns = ['name', 'phone1', 'dob', 'sex'];
            $missingColumns = array_diff($requiredColumns, $header);

            if (!empty($missingColumns)) {
                $foundColumns = implode(', ', array_keys(array_intersect_key($header, array_flip($requiredColumns))));
                $allFoundColumns = implode(', ', array_keys($header));
                return back()->withErrors([
                    'csv_file' => 'Missing required columns: ' . implode(', ', $missingColumns) . 
                    '. Found columns: ' . ($allFoundColumns ?: 'none') . 
                    '. Please ensure your file has the correct header row with: name, phone1, dob, sex'
                ]);
            }

            if (empty($data)) {
                return back()->withErrors(['csv_file' => 'No data rows found in the file.']);
            }

            // Process data in chunks of 20
            $chunkSize = 20;
            $chunks = array_chunk($data, $chunkSize);
            $totalChunks = count($chunks);
            $totalRows = count($data);

            // Process synchronously in chunks for immediate results
            // This ensures data is saved immediately without requiring queue worker
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            $failedRecords = [];

            DB::beginTransaction();
            
            try {
                foreach ($chunks as $chunkIndex => $chunk) {
                    Log::info("Processing chunk {$chunkIndex} of {$totalChunks}", [
                        'chunk_size' => count($chunk),
                        'user_id' => auth()->id()
                    ]);
                    
                    foreach ($chunk as $rowIndex => $rowData) {
                        try {
                            // Validate required fields
                            if (
                                empty($rowData['name']) || empty($rowData['phone1']) || empty($rowData['dob']) ||
                                empty($rowData['sex'])
                            ) {
                                throw new \Exception("Missing required fields");
                            }

                            // Validate sex
                            if (!in_array(strtoupper($rowData['sex']), ['M', 'F'])) {
                                throw new \Exception("Sex must be M or F");
                            }

                            // Handle region and district - convert names to IDs if provided
                            $regionId = null;
                            $districtId = null;

                            if (!empty($rowData['region_id'])) {
                                if (is_numeric($rowData['region_id'])) {
                                    $regionId = $rowData['region_id'];
                                } else {
                                    $region = Region::where('name', trim($rowData['region_id']))->first();
                                    $regionId = $region ? $region->id : null;
                                }
                            }

                            if (!empty($rowData['district_id'])) {
                                if (is_numeric($rowData['district_id'])) {
                                    $districtId = $rowData['district_id'];
                                } else {
                                    $district = District::where('name', trim($rowData['district_id']))->first();
                                    $districtId = $district ? $district->id : null;
                                }
                            }

                            // Format phone number
                            $phone1 = $this->formatPhoneNumber(trim($rowData['phone1']));
                            $phone2 = !empty($rowData['phone2']) ? $this->formatPhoneNumber(trim($rowData['phone2'])) : null;

                            // Determine category (Borrower/Guarantor) from file, default to Borrower
                            $rawCategory = trim($rowData['category'] ?? '');
                            $normalizedCategory = strtolower($rawCategory);
                            $category = in_array($normalizedCategory, ['borrower', 'guarantor'], true)
                                ? ucfirst($normalizedCategory)
                                : 'Borrower';

                            // Create customer data
                            $customerData = [
                                'name' => trim($rowData['name']),
                                'phone1' => $phone1,
                                'phone2' => $phone2,
                                'dob' => $rowData['dob'],
                                'sex' => strtoupper($rowData['sex']),
                                'region_id' => $regionId,
                                'district_id' => $districtId,
                                'work' => trim($rowData['work'] ?? ''),
                                'workAddress' => trim($rowData['workaddress'] ?? $rowData['workAddress'] ?? ''),
                                'idType' => trim($rowData['idtype'] ?? $rowData['idType'] ?? ''),
                                'idNumber' => trim($rowData['idnumber'] ?? $rowData['idNumber'] ?? ''),
                                'relation' => trim($rowData['relation'] ?? ''),
                                'description' => trim($rowData['description'] ?? ''),
                                'customerNo' => 100000 + (Customer::max('id') ?? 0) + 1,
                                'password' => Hash::make('1234567890'),
                                'branch_id' => auth()->user()->branch_id,
                                'company_id' => auth()->user()->company_id,
                                'registrar' => auth()->id(),
                                'dateRegistered' => now()->toDateString(),
                                'has_cash_collateral' => $request->has('has_cash_collateral'),
                                'category' => $category,
                            ];

                            $customer = Customer::create($customerData);

                            // Add cash collateral if selected
                            if ($request->has('has_cash_collateral') && $request->collateral_type_id) {
                                \App\Models\CashCollateral::create([
                                    'customer_id' => $customer->id,
                                    'type_id' => $request->collateral_type_id,
                                    'amount' => 0,
                                    'branch_id' => auth()->user()->branch_id,
                                    'company_id' => auth()->user()->company_id,
                                ]);
                            }

                            // Assign to individual group if not already in a group
                            $existingMembership = DB::table('group_members')->where('customer_id', $customer->id)->first();
                            if (!$existingMembership) {
                                DB::table('group_members')->insert([
                                    'group_id' => 1,
                                    'customer_id' => $customer->id,
                                    'status' => 'active',
                                    'joined_date' => now()->toDateString(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }

                            $successCount++;
                        } catch (\Exception $e) {
                            $errorMsg = "Row " . (($chunkIndex * $chunkSize) + $rowIndex + 2) . ": " . $e->getMessage();
                            $errors[] = $errorMsg;
                            $failedRecords[] = array_merge($rowData, [
                                'row_number' => ($chunkIndex * $chunkSize) + $rowIndex + 2,
                                'error_reason' => $errorMsg
                            ]);
                            $errorCount++;
                            Log::error('Failed to create customer in bulk upload', [
                                'row_data' => $rowData,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }

                if ($errorCount > 0) {
                    DB::rollBack();
                    
                    // Store failed records in session for export
                    $failedExportKey = 'failed_customer_upload_' . time();
                    session([$failedExportKey => $failedRecords]);
                    
                    return back()
                        ->withErrors(['csv_file' => "Upload completed with errors. {$errorCount} rows failed, {$successCount} rows succeeded."])
                        ->with('upload_errors', $errors)
                        ->with('failed_export_key', $failedExportKey)
                        ->with('failed_count', $errorCount);
                }

                DB::commit();

                $message = "Successfully uploaded {$successCount} customers.";
                if ($request->has('has_cash_collateral')) {
                    $message .= " Cash collateral applied to all customers.";
                }

                return redirect()->route('customers.index')->with('success', $message);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Bulk customer upload failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return back()->withErrors(['csv_file' => 'Failed to process file: ' . $e->getMessage()]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['csv_file' => 'Failed to process file: ' . $e->getMessage()]);
        }
    }
    
    // Download failed records export
    public function downloadFailedRecords(Request $request)
    {
        $exportKey = $request->get('key');
        $failedRecords = session($exportKey, []);
        
        if (empty($failedRecords)) {
            return back()->withErrors(['error' => 'Failed records not found.']);
        }
        
        $filename = 'failed_customer_upload_' . date('Y-m-d_His') . '.xlsx';
        return Excel::download(new CustomerBulkUploadFailedExport($failedRecords), $filename);
    }

    // Download sample Excel
    public function downloadSample()
    {
        $filename = 'customer_bulk_upload_sample_' . date('Y-m-d') . '.xlsx';
        return Excel::download(new CustomerBulkUploadSampleExport(), $filename);
    }
    
    // Old CSV download method (kept for backward compatibility)
    public function downloadSampleCSV()
    {
        $filename = 'customer_bulk_upload_sample.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () {
            $file = fopen('php://output', 'w');

            // Add headers
            fputcsv($file, [
                'name',
                'phone1',
                'phone2',
                'dob',
                'sex',
                'work',
                'workaddress',
                'idtype',
                'idnumber',
                'relation',
                'description'
            ]);

            // Add sample data
            fputcsv($file, [
                'John Doe',
                '0712345678',
                '0755123456',
                '1990-01-15',
                'M',
                'Teacher',
                'ABC School, Dar es Salaam',
                'National ID',
                '123456789',
                'Spouse',
                'Sample customer'
            ]);

            fputcsv($file, [
                'Jane Smith',
                '0723456789',
                '',
                '1985-05-20',
                'F',
                'Nurse',
                'City Hospital',
                'License',
                '987654321',
                'Parent',
                'Another sample'
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Send SMS message to customer
     */
    public function sendMessage(Request $request, $customerId)
    {
        try {
            // Decode the customer ID
            $decodedId = Hashids::decode($customerId);
            if (empty($decodedId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid customer ID'
                ], 400);
            }

            $customer = Customer::findOrFail($decodedId[0]);

            // Validate request
            $request->validate([
                'phone_number' => 'required|string',
                'message_content' => 'required|string|max:500',
            ]);

            $phoneNumber = $request->phone_number;
            $message = $request->message_content;

            // Use phone number as provided since it's already in clean format
            // Remove any spaces, dashes, or special characters except +
            $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

            // Ensure phone number is not empty after cleaning
            if (empty($phoneNumber)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid phone number provided.'
                ], 400);
            }

            // Send SMS using SmsHelper
            $smsResponse = \App\Helpers\SmsHelper::send($phoneNumber, $message);

            // Check if SMS was sent successfully
            $smsSuccess = is_array($smsResponse) ? ($smsResponse['success'] ?? false) : true;
            $smsMessage = is_array($smsResponse) ? ($smsResponse['message'] ?? 'SMS sent') : 'SMS sent';

            // Log the SMS activity (optional)
            \DB::table('sms_logs')->insert([
                'customer_id' => $customer->id,
                'phone_number' => $phoneNumber,
                'message' => $message,
                'response' => is_array($smsResponse) ? json_encode($smsResponse) : $smsResponse,
                'sent_by' => auth()->id(),
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Return response based on SMS result
            if ($smsSuccess) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully sent SMS to ' . $customer->name
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send SMS: ' . ($smsResponse['error'] ?? $smsMessage)
                ], 500);
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('SMS sending failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send SMS: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload multiple documents for a customer
     */
    public function uploadDocuments(Request $request, $encodedCustomerId)
    {
        try {
            $decoded = Hashids::decode($encodedCustomerId);
            if (empty($decoded)) {
                return response()->json(['success' => false, 'message' => 'Invalid customer id'], 400);
            }

            $customer = Customer::findOrFail($decoded[0]);

            $request->validate([
                'filetypes' => 'required|array',
                'filetypes.*' => 'required|exists:filetypes,id',
                'documents' => 'required|array',
                'documents.*' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:5120',
            ]);

            $filetypes = $request->input('filetypes', []);
            $documents = $request->file('documents', []);

            DB::beginTransaction();
            $uploadedCount = 0;
            $uploadedDocuments = [];
            $errors = [];

            foreach ($filetypes as $index => $filetypeId) {
                if (!isset($documents[$index])) {
                    continue;
                }

                try {
                    $file = $documents[$index];
                    $path = $file->store('documents', 'public');

                    // Check if this filetype already exists for this customer
                    $existing = DB::table('customer_file_types')
                        ->where('customer_id', $customer->id)
                        ->where('filetype_id', $filetypeId)
                        ->first();

                    if ($existing) {
                        // If filetype already exists, use "Multiple Documents" filetype instead
                        $filetypeId = 8; // Multiple Documents filetype
                    }

                    // Create new record
                    DB::table('customer_file_types')->insert([
                        'customer_id' => $customer->id,
                        'filetype_id' => $filetypeId,
                        'document_path' => $path,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $uploadedDocuments[] = [
                        'name' => $file->getClientOriginalName(),
                        'type' => \App\Models\Filetype::find($filetypeId)->name ?? 'Unknown',
                        'size' => $this->formatFileSize($file->getSize())
                    ];

                    $uploadedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to upload {$documents[$index]->getClientOriginalName()}: " . $e->getMessage();
                }
            }

            if ($uploadedCount === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No files were uploaded successfully',
                    'errors' => $errors
                ], 400);
            }

            DB::commit();

            $message = "Successfully uploaded {$uploadedCount} document(s)";
            if (!empty($errors)) {
                $message .= ". Some files failed: " . implode(', ', $errors);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'uploaded_count' => $uploadedCount,
                'documents' => $uploadedDocuments,
                'errors' => $errors
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Document upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload documents: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format file size in human readable format
     */
    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Delete a single customer document (pivot row)
     */
    public function deleteDocument(Request $request, $encodedCustomerId, $pivotId)
    {
        try {
            $decoded = Hashids::decode($encodedCustomerId);
            if (empty($decoded)) {
                return response()->json(['success' => false, 'message' => 'Invalid customer id'], 400);
            }

            $customerId = $decoded[0];
            $pivot = DB::table('customer_file_types')->where('id', $pivotId)->where('customer_id', $customerId)->first();
            if (!$pivot) {
                return response()->json(['success' => false, 'message' => 'Document not found'], 404);
            }

            // Delete file from storage if exists
            if (!empty($pivot->document_path)) {
                try {
                    \Storage::disk('public')->delete($pivot->document_path);
                } catch (\Exception $e) {
                    // ignore storage deletion errors
                }
            }

            DB::table('customer_file_types')->where('id', $pivotId)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stream a customer document for viewing in the browser
     */
    public function viewDocument($encodedCustomerId, $pivotId)
    {
        $decoded = \Vinkla\Hashids\Facades\Hashids::decode($encodedCustomerId);
        if (empty($decoded)) {
            abort(404);
        }

        $customerId = $decoded[0];
        $pivot = \DB::table('customer_file_types')
            ->where('id', $pivotId)
            ->where('customer_id', $customerId)
            ->first();

        if (!$pivot || empty($pivot->document_path)) {
            abort(404);
        }

        $disk = \Storage::disk('public');
        if (!$disk->exists($pivot->document_path)) {
            abort(404);
        }

        $mimeType = $disk->mimeType($pivot->document_path) ?: 'application/octet-stream';
        $contents = $disk->get($pivot->document_path);
        return response($contents, 200)->header('Content-Type', $mimeType);
    }

    /**
     * Download a customer document as attachment
     */
    public function downloadDocument($encodedCustomerId, $pivotId)
    {
        $decoded = \Vinkla\Hashids\Facades\Hashids::decode($encodedCustomerId);
        if (empty($decoded)) {
            abort(404);
        }

        $customerId = $decoded[0];
        $pivot = \DB::table('customer_file_types')
            ->where('id', $pivotId)
            ->where('customer_id', $customerId)
            ->first();

        if (!$pivot || empty($pivot->document_path)) {
            abort(404);
        }

        $disk = \Storage::disk('public');
        if (!$disk->exists($pivot->document_path)) {
            abort(404);
        }

        $filename = basename($pivot->document_path);
        return response()->streamDownload(function () use ($disk, $pivot) {
            echo $disk->get($pivot->document_path);
        }, $filename);
    }

}
