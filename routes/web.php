<?php

use App\Http\Controllers\ReceiptVerifyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $frontendEntry = public_path('index.html');

    if (is_file($frontendEntry)) {
        return response()->file($frontendEntry);
    }

    return ['Laravel' => app()->version()];
});

require __DIR__.'/auth.php';

Route::get('/verify/receipts/{receiptNumber}', [ReceiptVerifyController::class, 'show']);

Route::fallback(function () {
    if (request()->is('api/*')) {
        abort(404);
    }

    $frontendEntry = public_path('index.html');

    if (is_file($frontendEntry)) {
        return response()->file($frontendEntry);
    }

    abort(404);
});
