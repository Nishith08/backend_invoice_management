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

        if ($role === 'admin') {
            $invoices = Invoice::where('department', $department)->orderByDesc('created_at')->get();
        } else {
            $invoices = Invoice::where('current_role', $role)->orderByDesc('created_at')->get();
        }

        // Add full document URL
        $invoices->transform(function ($invoice) {
            $invoice->document_url = Storage::url($invoice->document);
            return $invoice;
        });

        return response()->json($invoices);
    }

    public function store(Request $request)
    {
        // Validate request
        $request->validate([
            'title' => 'required|string',
            'inv_no' => 'required|string',
            'inv_amt' => 'required|string',
            'inv_type' => 'required|string',
            'comment' => 'nullable|string',
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png',
        ]);

         // Store uploaded file
        // $path = $request->file('document')->store('invoices','public');
        // $path = $request->file('document')->store('', 'invoices');
        $path = $request->file('document')->store('invoices', 'invoices');
        $department = Auth::user()->department;
        
        // Create invoice entry
        $invoice = Invoice::create([
            'title' => $request->title,
            'inv_no' => $request->inv_no,
            'inv_amt' => $request->inv_amt,
            'inv_type' => $request->inv_type,
            'comment' => $request->comment,
            'document' => $path,
            'status' => 'pending',
            'current_role' => 'accounts_1st', // first approver role
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
