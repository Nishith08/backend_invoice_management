<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

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
        // Validate request
        $request->validate([
            'title' => 'required|string',
            'inv_no' => 'required|string',
            'correction' => 'required|string',
            'inv_amt' => 'required|string',
            'inv_type' => 'required|string',
            'comment' => 'nullable|string',
            'document' => 'required|array',
            'document.*' => 'file|mimes:pdf,jpg,jpeg,png',
        ]);

         // Store uploaded file
        // $path = $request->file('document')->store('invoices','public');
        // $path = $request->file('document')->store('', 'invoices');
        $paths = [];
foreach ($request->file('document') as $file) {
    $paths[] = $file->store('invoices', 'invoices');
}
        // $path = $request->file('document')->store('invoices', 'invoices');
        $department = Auth::user()->department;
        
        
    // NEW: If correction = 1 → get latest invoice for same inv_no
    $prevInvoice = null;
    if ($request->correction == 1) {
        $prevInvoice = Invoice::where('inv_no', $request->inv_no)
                              ->orderByDesc('created_at')
                              ->first();
    }

    // NEW: Copy role + rejectedTo_role if correction
    $newCurrentRole = $request->correction == 1
        ? ($prevInvoice ? $prevInvoice->current_role : 'accounts_1st')
        : 'accounts_1st';

    $newRejectedToRole = $request->correction == 1
        ? ($prevInvoice ? $prevInvoice->rejectedTo_role : [])
        : [];

        // Create invoice entry
        $invoice = Invoice::create([
            'title' => $request->title,
            'inv_no' => $request->inv_no,
            'inv_amt' => $request->inv_amt,
            'inv_type' => $request->inv_type,
            'comment' => $request->comment,
            'document' => json_encode($paths),
            'status' => ($request->correction) ? 'Corrected':'pending',
            'current_role' => $newCurrentRole,
            // NEW: store previous rejected roles (if any)
            'rejectedTo_role' => $newRejectedToRole,
            'department' => $department,
        ]);
        InvoiceActionLog::create([
            'invoice_id' => $invoice->id,
            'user_id' => Auth::id(),
            'role' => 'admin',   // or Auth::user()->role if admin
            'action' => 'created',
            'comment' => 'Invoice created by admin',
            'seen' => false,
        ]);


        return response()->json($invoice, 201);
    }
}
