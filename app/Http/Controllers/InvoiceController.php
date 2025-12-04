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

        // 1️⃣ Fetch ALL invoices
        if ($role === 'admin') {
            $allInvoices = Invoice::where('department', $department)
                                ->orderByDesc('created_at')
                                ->get();
        } else {
            $allInvoices = Invoice::orderByDesc('created_at')->get();
        }

        // 2️⃣ Group by inv_no → keep the newest invoice
        $latestInvoices = $allInvoices->groupBy('inv_no')->map(function ($group) {
            return $group->first();
        })->values();

        // 3️⃣ Apply SAME FILTER logic as original query
        if ($role !== 'admin') {
            $latestInvoices = $latestInvoices->filter(function ($invoice) use ($role) {

                // Properly decode JSON
                $rtRole = $invoice->rejectedTo_role;
                if (is_string($rtRole)) {
                    $rtRole = json_decode($rtRole, true);
                }

                // Exact matching behavior
                return 
                    $invoice->current_role === $role ||
                    (is_array($rtRole) && in_array($role, $rtRole));
            })->values();
        }

        // 4️⃣ Add document URL
        $latestInvoices->transform(function ($inv) {
            $inv->document_url = Storage::url($inv->document);
            return $inv;
        });

        return response()->json($latestInvoices);
    }


    public function store(Request $request)
    {
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
            'document.*'  => 'file|mimes:pdf,jpg,jpeg,png',
        ];

        // Extra validation only when creating new invoice
        if ($request->correction == 0) {
          
            $rules['kyc_required'] = 'required|in:yes,no';

            if ($request->kyc_required === 'yes') {
                $rules['kyc_docs'] = 'required|array';
                $rules['kyc_docs.*'] = 'file|mimes:pdf,jpg,jpeg,png';
            }
        }

        $request->validate($rules);

        /*
        |--------------------------------------------------------------------------
        | UPLOAD INVOICE DOCUMENTS
        |--------------------------------------------------------------------------
        */
        $paths = [];
        foreach ($request->file('document') as $file) {
            $paths[] = $file->store('invoices', 'invoices');
        }

        $department = Auth::user()->department;

        /*
        |--------------------------------------------------------------------------
        | CORRECTION MODE: FETCH PREVIOUS INVOICE
        |--------------------------------------------------------------------------
        */
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

        // POP LAST ITEM ONLY IN CORRECTION
        if ($request->correction == 1 && !empty($prevRejected)) {
            array_pop($prevRejected);
        }

        $newRejectedToRole = $request->correction == 1 ? $prevRejected : [];


        /*
        |--------------------------------------------------------------------------
        | KYC DOCS UPLOAD (ONLY FOR NEW INVOICE)
        |--------------------------------------------------------------------------
        */
        $kycPaths = [];

        if ($request->correction == 0 && $request->kyc_required === 'yes') {
            foreach ($request->file('kyc_docs') as $file) {
                $kycPaths[] = $file->store('invoices/kyc', 'invoices');
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

            // NEW FIELDS (only if not correction)
            
            'kyc_required'    => $request->correction == 0 ? $request->kyc_required : ($prevInvoice->kyc_required ?? 'no'),
            'kyc_docs'        => $request->correction == 0 
                                    ? json_encode($kycPaths)
                                    : ($prevInvoice->kyc_docs ?? json_encode([])),
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
                                : 'Invoice created by admin',
            'seen'       => false,
        ]);

        return response()->json($invoice, 201);
    }


}
