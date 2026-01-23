<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class InvoiceActionController extends Controller
{
    public function action(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:approve,reject',
            'comment' => 'nullable|string',
            'feedback' => 'nullable|string',
            'rejectedRole' => 'nullable|string',
            'poRequired' => 'nullable|string',
        ]);

        $invoice = Invoice::findOrFail($id);
        $user = Auth::user();

        $action = $request->action;
        $comment = $request->comment;
        $rejectedRole = $request->rejectedRole;
        $poRequired = $request->poRequired;
        //Log::info('poRequired value', ['poRequired' => $poRequired]);
        $query = $request->input('feedback');
        if ($query == "") {
            $query = "-";
        }
        if(!$rejectedRole){ 
            $rejectedRole = '';
        }
        // Log entry
        InvoiceActionLog::create([
            'invoice_id' => $invoice->id,        
            'user_id' => $user->id,
            'role' => $user->role,
            'action' => $action,
            'comment' => $comment,
            'query' => $query,
            'seen' => false,
            'rejected_to' => $rejectedRole,
        ]);
        $crole = $invoice->current_role;
        //Log::info('poRequired value', ['crole' => $crole]);
        if($action === 'reject') {
            
            if($crole == "purchase_office" && $user->role != "accounts_1st"){
                $rejectedRole = "accounts_1st";
                
            }
            // ----------------------------
            // STORE rejectedRole AS ARRAY
            // ----------------------------
            $existing = $invoice->rejectedTo_role ?? [];

            if (!is_array($existing)) {
                $existing = json_decode($existing, true) ?? [];
            }

            if ($rejectedRole && !in_array($rejectedRole, $existing)) {
                $existing[] = $rejectedRole;
            }

            $invoice->rejectedTo_role = $existing;

            // workflow logic
            
                $invoice->status = 'rejected';
            

        }elseif($action === 'approve'){
            // decode rejectedTo_role into array (if present)
            $prevRejected = is_string($invoice->rejectedTo_role)
                ? json_decode($invoice->rejectedTo_role, true)
                : ($invoice->rejectedTo_role ?? []);
        
           
            // Only pop last rejected role when status is 'corrected' and there is at least one rejected role
            if (!empty($prevRejected)) {
                //Log::info('InvoiceActionController: popping previous rejected role', $prevRejected);
                array_pop($prevRejected);
                 //Log::info('after pop', $prevRejected);
                 $invoice->status = 'corrected';
                 $invoice->rejectedTo_role = $prevRejected;
                // $invoice_id = $invoice->id;
                // $purchaseinvolved = '';
                // if($prevRejected == []){
                // $invoice->status = 'corrected';
                // }else{
                //  $invoice->status = 'pending';
                //  $invoice->current_role = "accounts_2nd"; 
                // }
            } else {
                if($crole == "accounts_1st" && $poRequired == "yes"){
                    $nextRole = "purchase_office";
                }else if($crole == "purchase_office"){
                    $nextRole = "accounts_1st";
                }else{
                    $nextRole = $this->getNextRole($user->role);
                }
                if ($nextRole!== 'final_accountant') {
                //Log::info('after final', ['nrole' => $nextRole]);
                    
                    $invoice->current_role = $nextRole; 
                    $invoice->status = 'pending';
                } else {
                    //$invoice->current_role = 'final_accountant';
                    $invoice->status = 'completed';
                }
            }
        }

        $invoice->save();

        return response()->json(['message' => 'Action recorded successfully', 'invoice' => $invoice]);
    }


    private function getNextRole($currentRole)
    {
        $roles = ['accounts_1st', 'accounts_2nd', 'accounts_3rd', 'final_accountant'];
        $index = array_search($currentRole, $roles);
        return ($index !== false && $index < count($roles) - 1) ? $roles[$index + 1] : null;
    }

    private function getPreviousRole($currentRole)
    {
        $roles = ['accounts_1st', 'accounts_2nd', 'accounts_3rd', 'final_accountant'];
        $index = array_search($currentRole, $roles);
        return ($index !== false && $index > 0) ? $roles[$index - 1] : null;
    }

    // Notification logs filtered by role viewing rules
    public function latestLogsForRole(Request $request)
    {
        $role = $request->query('role');
        $user = Auth::user();
        $query = InvoiceActionLog::with('invoice');

        if ($role === 'admin') {
             if ($user && $user->department) {
            $query->whereHas('invoice', function ($q) use ($user) {
                $q->where('department', $user->department);
            });
        }
        } elseif ($role === 'accounts_1st') {
            $query->whereIn('role', ['admin', 'accounts_2nd', 'accounts_3rd','final_accountant']);
        } elseif ($role === 'accounts_2nd') {
            $query->whereIn('role', ['admin','accounts_1st', 'accounts_3rd','final_accountant']);
        } elseif ($role === 'accounts_3rd') {
            $query->whereIn('role', ['admin','accounts_1st', 'accounts_2nd', 'final_accountant']);
        } elseif ($role === 'final_accountant') {
              $query->whereIn('role', ['admin','accounts_1st', 'accounts_2nd', 'accounts_3rd']);
        } else {
            return response()->json(['logs' => [], 'unseen' => false]);
        }

        $logs = $query->orderByDesc('created_at')->take(10)->get();
        // Map logs to include department for each invoice
        $logs = $logs->map(function($log) {
            return [
                'id' => $log->id,
                'invoice_id' => $log->invoice_id,
                'user_id' => $log->user_id,
                'role' => $log->role,
                'action' => $log->action,
                'comment' => $log->comment,
                'query' => $log->query,
                'seen' => $log->seen,
                'created_at' => $log->created_at,
                'invoice' => $log->invoice,
                // Add department from invoice relation
                'department' => $log->invoice ? $log->invoice->department : null,
            ];
        });
        $unseen = $query->where('seen', false)->exists();

        return response()->json([
            'logs' => $logs,
            'unseen' => $unseen
        ]);
    }

    // Mark logs as seen filtered by role viewing rules
    public function markLogsAsSeen(Request $request)
    {
        $role = $request->query('role');
        $query = InvoiceActionLog::query();

        if ($role === 'admin') {
            // All logs for admin
        } elseif ($role === 'accounts_1st') {
            $query->whereIn('role', ['admin', 'accounts_2nd']);
        } elseif ($role === 'accounts_2nd') {
            $query->whereIn('role', ['accounts_1st', 'accounts_3rd']);
        } elseif ($role === 'accounts_3rd') {
            $query->where('role', 'accounts_2nd');
        } elseif ($role === 'final_accountant') {
            $query->where('role', 'accounts_3rd');
        } else {
            return response()->json(['ok' => false]);
        }

        $query->where('seen', false)->update(['seen' => true]);

        return response()->json(['ok' => true]);
    }

    // Optional: Get full log history unfiltered (admin use)
    public function allLogs()
    {
        $logs = InvoiceActionLog::orderByDesc('created_at')->get();
        return response()->json($logs);
    }
    public function invoiceLogHistory($invoice_id)
    {
        // STEP 1 — Get selected invoice
        $selectedInvoice = Invoice::findOrFail($invoice_id);

        // STEP 2 — Get inv_no of selected invoice
        $invNo = $selectedInvoice->inv_no;

        // STEP 3 — Fetch all invoices with same inv_no in DESC order (latest first)
        $invoices = Invoice::where('inv_no', $invNo)
            ->orderBy('id', 'desc')
            ->get();

        // STEP 4 — Fetch logs for these invoices
        $invoiceIds = $invoices->pluck('id');

        $allLogs = InvoiceActionLog::with('user')
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('created_at', 'desc')
            ->get();

        // STEP 5 — Attach logs to each invoice
        $invoicesWithLogs = $invoices->map(function ($inv) use ($allLogs) {
            $inv->logs = $allLogs->where('invoice_id', $inv->id)->values();
            return $inv;
        });

        return response()->json([
            'inv_no' => $invNo,
            'invoices' => $invoicesWithLogs
        ]);
    }  


    public function finalUpload(Request $request, $id)
    {
        // Validate inputs, file required only if action is approve
        $request->validate([
            'final_doc' => 'nullable|file|mimes:pdf,jpg,jpeg,png',
            'comment' => 'nullable|string',
            'action' => 'required|in:approve,reject',
        ]);

        $invoice = Invoice::findOrFail($id);
        $user = Auth::user();

        $action = $request->input('action');
        $comment = $request->input('comment');

        // Store file if present and action is approve
        if ($request->hasFile('final_doc') && $action === 'approve') {
           
            $path = $request->file('final_doc')->store('final_documents', 'invoices');
            $invoice->final_document =  $path;
        }

        // Add log entry
        InvoiceActionLog::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'role' => $user->role,
            'action' => $action,
            'comment' => $comment,
            'seen' => false,
        ]);

        // Update status based on action
        if ($action === 'approve') {
            $invoice->status = 'final_approved';
        } elseif ($action === 'reject') {
            $invoice->status = 'final_rejected';
        }

        $invoice->save();

        return response()->json(['message' => 'Final upload action saved', 'invoice' => $invoice]);
    }
}
