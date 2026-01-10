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
            //Log::info('poRequired value'.$allInvoices);
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

                // Exact matching behavior: compare with current_role or the last element
                $lastRtRole = null;
                if (is_array($rtRole) && count($rtRole) > 0) {
                    $lastRtRole = $rtRole[count($rtRole) - 1];
                }

                // If invoice is corrected and there are pending rejected roles,
                // ignore `current_role` and match only against the last rejected role.
                if ($invoice->status === 'corrected' && is_array($rtRole) && count($rtRole) > 0) {
                    return $lastRtRole === $role;
                }else{
                    return $invoice->current_role === $role || ($lastRtRole !== null && $lastRtRole === $role);
                }

               
            })->values();
        }

        // 4️⃣ Add document URL and `rej_yesno` (1 = has rejected roles, 2 = none)
        $latestInvoices->transform(function ($inv, $index) {
            $inv->document_url = Storage::url($inv->document);
            $curr_role = $inv->current_role;

            // Check if there's an approve action by purchase_office for this invoice
            $hasApprove = InvoiceActionLog::where('invoice_id', $inv->id)
                ->where('action', 'approve')
                ->where('role', 'purchase_office')
                ->exists();

            if ($curr_role == 'accounts_1st' && $hasApprove) {
                $inv->dyn = 0;
            } else if($curr_role == 'accounts_1st'){
                $inv->dyn = 2;
            } else if($curr_role == 'purchase_office'){
                $inv->dyn = 1;   
            } else if($curr_role !== 'purchase_office' && $curr_role !== 'accounts_1st'){
                $inv->dyn = 0;
            }

            // $rej_yesno = $inv->status;
            // if ($rej_yesno == 'rejected') {
            //     $rej_yesno = 1;
            // } else if ($rej_yesno == 'pending') {
            //     $rej_yesno = 0;
            // }
            // $inv->rej_yesno = $rej_yesno;

            return $inv;
        });

        return response()->json($latestInvoices);
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
