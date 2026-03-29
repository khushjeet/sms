<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Email\EventNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyPaymentRecordedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $paymentId)
    {
        $this->onQueue('emails');
    }

    public function handle(EventNotificationService $notifications): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if (!$payment) {
            return;
        }

        $notifications->notifyPaymentRecorded($payment);
    }
}
