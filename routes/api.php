<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceActionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Log;

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/action', [InvoiceActionController::class, 'action']);
    Route::get('/invoices/{id}/logs', [InvoiceActionController::class, 'logs']);

    Route::get('/logs/latest', [InvoiceActionController::class, 'latestLogsForRole']);
    Route::post('/logs/mark-seen', [InvoiceActionController::class, 'markLogsAsSeen']);
    Route::get('/logs/all', [InvoiceActionController::class, 'allLogs']);

    Route::get('/logs/invoice/{id}', [InvoiceActionController::class, 'invoiceLogHistory']);
    Route::post('/invoices/{id}/final-upload', [InvoiceActionController::class, 'finalUpload']);
});

Route::post('/notify', [NotificationController::class, 'send']);
