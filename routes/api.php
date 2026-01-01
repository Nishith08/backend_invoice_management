<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceActionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Storage;


// Route::get('/download/{path}', function ($path) {

//     \Log::info("Downloading file: {$path}");

//     // Normalize path
//     $path = ltrim($path, '/');

//     // Choose disk based on environment
//     $disk ='invoices';

//     if (!Storage::disk($disk)->exists($path)) {
//         \Log::error("File not found on disk [$disk]: $path");
//         abort(404, "File not found");
//     }

//     return Storage::disk($disk)->download($path);

// })->where('path', '.*');

        Route::get('/download/{path}', function ($path) {
            $fullPath = storage_path('app/public/' . $path);
Log::info("Download request for path: $fullPath");
            if (!file_exists($fullPath)) {
                abort(404, "File not found: $path");
            }

            return response()->download($fullPath);
        })->where('path', '.*');


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
