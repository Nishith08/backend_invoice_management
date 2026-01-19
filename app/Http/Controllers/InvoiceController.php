<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{   

    public function index(Request $request) 
    {
        $user = Auth::user();
        $role = $user->role;
        $department = $user->department;
        $userId = $user->id;

        // 1️⃣ Fetch ALL invoices
        if ($role === 'admin') {
            $allInvoices = Invoice::where('department', $department)
                ->orderByDesc('created_at')
                ->get();
        } else {
            $allInvoices = Invoice::orderByDesc('created_at')->get();
        }

        // 🔹 Count how many times each inv_no exists
        $invNoCounts = $allInvoices->groupBy('inv_no')->map(function ($group) {
            return $group->count();
        });

        // 2️⃣ Group by inv_no → keep the newest invoice
        $latestInvoices = $allInvoices->groupBy('inv_no')->map(function ($group) {
            return $group->first();
        })->values();

        // Filter invoices based on role hierarchy
        $roleHierarchy = ['admin', 'accounts_1st', 'purchase_office', 'accounts_2nd', 'accounts_3rd', 'final_accountant'];
        $loggedInRoleIndex = array_search($role, $roleHierarchy);

        if ($loggedInRoleIndex !== false) {
            $latestInvoices = $latestInvoices->filter(function ($invoice) use ($loggedInRoleIndex, $roleHierarchy) {
                $invoiceRoleIndex = array_search($invoice->current_role, $roleHierarchy);
                return $invoiceRoleIndex !== false && $invoiceRoleIndex >= $loggedInRoleIndex;
            })->values();
        }

        // 3️⃣ Mark entries with displayYesNo, inv_found & approvedYesNo
        $latestInvoices = $latestInvoices->map(function ($invoice) use ($role, $invNoCounts, $userId) {

            // ✅ Duplicate inv_no check
            $invoice->inv_found = isset($invNoCounts[$invoice->inv_no]) 
                && $invNoCounts[$invoice->inv_no] >= 2;

            // ✅ Check if THIS USER has approved THIS invoice even once
            $invoice->approvedYesNo = InvoiceActionLog::where('invoice_id', $invoice->id)
                ->where('user_id', $userId)
                ->where('action', 'approve')
                ->exists();

            if ($role === 'admin') {
                $invoice->displayYesNo = true;
                return $invoice;
            }

            $rtRole = $invoice->rejectedTo_role;
            if (is_string($rtRole)) {
                $rtRole = json_decode($rtRole, true);
            } else if ($rtRole === null) {
                $rtRole = [];
            }

            $lastRtRole = null;
            if (is_array($rtRole) && count($rtRole) > 0) {
                $lastRtRole = $rtRole[count($rtRole) - 1];
            }

            if ($invoice->status === 'corrected' && count($rtRole) > 0) {
                $invoice->displayYesNo = ($lastRtRole === $role);
            } else {
                $invoice->displayYesNo = (
                    $invoice->current_role === $role || 
                    ($lastRtRole !== null && $lastRtRole === $role)
                );
            }

            if ($invoice->current_role === $role && !empty($rtRole)) {
                $invoice->displayYesNo = false;
            }

            return $invoice;
        })->values();

        // 4️⃣ Add document URL and `dyn` value
        $latestInvoices->transform(function ($inv) {

            $inv->document_url = Storage::url($inv->document);
            $curr_role = $inv->current_role;

            $hasApprove = InvoiceActionLog::where('invoice_id', $inv->id)
                ->where('action', 'approve')
                ->where('role', 'purchase_office')
                ->exists();

            if ($curr_role == 'accounts_1st' && $hasApprove) {
                $inv->dyn = 0;
            } else if ($curr_role == 'accounts_1st') {
                $inv->dyn = 2;
            } else if ($curr_role == 'purchase_office') {
                $inv->dyn = 1;
            } else {
                $inv->dyn = 0;
            }

            return $inv;
        });

        // 5️⃣ Calculate 4 Counts for Dashboard
        $pendingCount = 0;
        $approvedCount = 0;
        $rejectedCount = 0;
        $completedCount = 0;

        foreach ($latestInvoices as $invoice) {
            // COMPLETED: Simple check for completed status
            if ($invoice->status === 'completed') {
                $completedCount++;
            }

            // REJECTED: Check if rejectedTo_role contains current user's role
            $rtRole = $invoice->rejectedTo_role;
            if (is_string($rtRole)) {
                $rtRole = json_decode($rtRole, true);
            }
            if (!is_array($rtRole)) {
                $rtRole = [];
            }
            
            if (in_array($role, $rtRole)) {
                $rejectedCount++;
            }

            // PENDING: Action is open for logged-in user
            // - New invoice where current_role is user's role
            // - Corrected invoice where last rejectedTo_role is user's role
            $isPending = false;
            
            if ($invoice->status === 'pending' && $invoice->current_role === $role) {
                $isPending = true;
            } else if (!empty($rtRole)) {
                $lastRtRole = end($rtRole);
                
                if ($lastRtRole === $role) {
                $isPending = true;
                }
            }
            
            if ($isPending) {
                $pendingCount++;
            }

            // APPROVED: User has taken action (created invoice or approved it)
            $userApprovedInvoice = InvoiceActionLog::where('invoice_id', $invoice->id)
                ->where('user_id', $userId)
                ->whereIn('action', ['approve', 'create'])
                ->exists();

            // OR user is admin and created the invoice (all invoices for admin)
            $isCreatedByUser = false;
            if ($role === 'admin') {
                // Check if this user created the invoice (created_by field would be needed, or check first log)
                $firstLog = InvoiceActionLog::where('invoice_id', $invoice->id)
                    ->where('user_id', $userId)
                    ->first();
                $isCreatedByUser = $firstLog !== null;
            }

            if ($userApprovedInvoice || $isCreatedByUser) {
                $approvedCount++;
            }
        }

        return response()->json([
            'invoices' => $latestInvoices,
            'counts' => [
                'pending' => $pendingCount,
                'approved' => $approvedCount,
                'rejected' => $rejectedCount,
                'completed' => $completedCount,
            ]
        ]);
    }




    public function store(Request $request)
    {
        Log::info('Invoice store called');

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */
        $rules = [
            'title'       => 'required|string',
            'inv_no'      => 'required|string',
            'correction'  => 'required|string',
            'inv_amt'     => 'required|string',
            'inv_type'    => 'required|string',
            'comment'     => 'nullable|string',
            'document'    => 'required|array',
            'kyc_required'=> 'required|in:yes,no',
        ];

        if ($request->kyc_required === 'yes') {
            $rules['kyc_docs'] = 'required|array';
        }

        $request->validate($rules);

        Log::info('Validation passed');

        /*
        |--------------------------------------------------------------------------
        | UPLOAD INVOICE DOCUMENTS (Existing + New)
        |--------------------------------------------------------------------------
        */
        $paths = [];

        $documents = $request->input('document', []);

        if (empty($documents)) {
            $documents = array_keys($request->file('document') ?? []);
        }

        foreach ($documents as $key => $item) {

            // Existing file path
            if (is_string($item)) {
                $paths[] = $item;
            }

            // New uploaded file
            if ($request->hasFile("document.$key")) {
                $file = $request->file("document.$key");

                if (!in_array($file->getClientOriginalExtension(), ['pdf','jpg','jpeg','png'])) {
                    return response()->json(['error' => 'Invalid document file type'], 422);
                }

                $path = $file->store('invoices', 'invoices');
                Log::info('Invoice document stored:', [$path]);
                $paths[] = $path;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CORRECTION MODE: FETCH PREVIOUS INVOICE
        |--------------------------------------------------------------------------
        */
        $department = Auth::user()->department;

        $prevInvoice = null;
        if ($request->correction == 1) {
            $prevInvoice = Invoice::where('inv_no', $request->inv_no)
                ->orderByDesc('created_at')
                ->first();
        }

        /*
        |--------------------------------------------------------------------------
        | ROLE / REJECTED ARRAY LOGIC
        |--------------------------------------------------------------------------
        */
        $newCurrentRole = ($request->correction == 1 && $prevInvoice)
            ? $prevInvoice->current_role
            : 'accounts_1st';

        $prevRejected = [];
        if ($prevInvoice) {
            $prevRejected = is_string($prevInvoice->rejectedTo_role)
                ? json_decode($prevInvoice->rejectedTo_role, true)
                : ($prevInvoice->rejectedTo_role ?? []);
        }

        if ($request->correction == 1 && !empty($prevRejected)) {
            array_pop($prevRejected);
        }

        $newRejectedToRole = $request->correction == 1 ? $prevRejected : [];

        /*
        |--------------------------------------------------------------------------
        | UPLOAD KYC DOCUMENTS (Existing + New)
        |--------------------------------------------------------------------------
        */
        $kycPaths = [];

        if ($request->kyc_required === 'yes') {

            $kycDocs = $request->input('kyc_docs', []);

            if (empty($kycDocs)) {
                $kycDocs = array_keys($request->file('kyc_docs') ?? []);
            }

            foreach ($kycDocs as $key => $item) {

                // Existing file path
                if (is_string($item)) {
                    $kycPaths[] = $item;
                }

                // New uploaded file
                if ($request->hasFile("kyc_docs.$key")) {
                    $file = $request->file("kyc_docs.$key");

                    if (!in_array($file->getClientOriginalExtension(), ['pdf','jpg','jpeg','png','jfif'])) {
                        return response()->json(['error' => 'Invalid KYC file type'], 422);
                    }

                    $path = $file->store('kyc', 'invoices');
                    Log::info('KYC document stored:', [$path]);
                    $kycPaths[] = $path;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE NEW INVOICE ENTRY
        |--------------------------------------------------------------------------
        */
        $invoice = Invoice::create([
            'title'           => $request->title,
            'inv_no'          => $request->inv_no,
            'inv_amt'         => $request->inv_amt,
            'inv_type'        => $request->inv_type,
            'comment'         => $request->comment,
            'document'        => json_encode($paths),
            'status'          => ($request->correction == 1) ? 'corrected' : 'pending',
            'current_role'    => $newCurrentRole,
            'rejectedTo_role' => json_encode($newRejectedToRole),
            'department'      => $department,
            'kyc_required'    => $request->kyc_required,
            'kyc_docs'        => json_encode($kycPaths),
        ]);

        /*
        |--------------------------------------------------------------------------
        | LOG ENTRY
        |--------------------------------------------------------------------------
        */
        InvoiceActionLog::create([
            'invoice_id' => $invoice->id,
            'user_id'    => Auth::id(),
            'role'       => 'admin',
            'action'     => 'created',
            'comment'    => $request->correction == 1
                                ? 'Correction submitted for invoice'
                                : 'Invoice Generated',
            'seen'       => false,
        ]);

        return response()->json($invoice, 201);
    }



}
