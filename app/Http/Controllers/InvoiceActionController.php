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
        ]);
        
        $invoice = Invoice::findOrFail($id);
        $user = Auth::user();

        $action = $request->action;
        $comment = $request->comment;
    
    // $query = $request->feedback;
    $query = $request->input('feedback');
    if($query==""){
$query = "-";
    }
    //Log::info('InvoiceActionController action query: '.$query );
        InvoiceActionLog::create([
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'role' => $user->role,
            'action' => $action,
            'comment' => $comment,
            'query' => $query,
            'seen' => false, // new log initially unseen
        ]);

        if ($action === 'reject') {
            // Move back to previous role in workflow if exists
            $previousRole = $this->getPreviousRole($user->role);
            if ($previousRole) {
                $invoice->current_role = $previousRole;
                $invoice->status = 'pending';
            } else {
                // No previous role, mark rejected without changing current_role
                $invoice->status = 'rejected';
            }
        } elseif ($action === 'approve') {
            // Move to next role in workflow
            $nextRole = $this->getNextRole($user->role);
            if ($nextRole) {
                $invoice->current_role = $nextRole;
                $invoice->status = 'pending';
            } else {
                // Final approval
                $invoice->status = 'completed';
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
            $query->whereIn('role', ['admin', 'accounts_2nd']);
        } elseif ($role === 'accounts_2nd') {
            $query->whereIn('role', ['accounts_1st', 'accounts_3rd']);
        } elseif ($role === 'accounts_3rd') {
            $query->where('role', 'accounts_2nd');
        } elseif ($role === 'final_accountant') {
            $query->where('role', 'accounts_3rd');
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
        $logs = InvoiceActionLog::with('user')->where('invoice_id', $invoice_id)
            ->orderBy('created_at', 'asc')
            ->get();

        $invoice = Invoice::findOrFail($invoice_id);

        return response()->json([
            'invoice' => $invoice,
            'logs' => $logs
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
            // $file = $request->file('final_doc');
            // $path = $file->store('final_documents', 'public'); // Store on public disk
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
