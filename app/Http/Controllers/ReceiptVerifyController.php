<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Http\Request;

class ReceiptVerifyController extends Controller
{
    public function show(Request $request, string $receiptNumber)
    {
        $receipt = Receipt::query()
            ->where('receipt_number', $receiptNumber)
            ->first();

        if ($receipt) {
            return response()->view('receipts.verify', [
                'receiptNumber' => $receipt->receipt_number,
                'type' => 'receipt',
                'amount' => $receipt->amount,
                'paidAt' => $receipt->paid_at,
                'paymentMethod' => $receipt->payment_method,
            ]);
        }

        $payment = Payment::query()
            ->where('receipt_number', $receiptNumber)
            ->first();

        if ($payment) {
            return response()->view('receipts.verify', [
                'receiptNumber' => $payment->receipt_number,
                'type' => 'payment',
                'amount' => $payment->amount,
                'paidAt' => $payment->payment_date,
                'paymentMethod' => $payment->payment_method,
            ]);
        }

        return response()->view('receipts.verify', [
            'receiptNumber' => $receiptNumber,
            'type' => 'unknown',
            'amount' => null,
            'paidAt' => null,
            'paymentMethod' => null,
        ], 404);
    }
}

