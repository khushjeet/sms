<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReceiptVerifyController;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

require __DIR__.'/auth.php';

Route::get('/verify/receipts/{receiptNumber}', [ReceiptVerifyController::class, 'show']);
