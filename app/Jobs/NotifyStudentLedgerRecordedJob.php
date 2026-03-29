<?php

namespace App\Jobs;

use App\Models\StudentFeeLedger;
use App\Services\Email\EventNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyStudentLedgerRecordedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int, string> $extraLines
     */
    public function __construct(
        public int $ledgerId,
        public ?string $subject = null,
        public ?string $headline = null,
        public array $extraLines = []
    ) {
        $this->onQueue('emails');
    }

    public function handle(EventNotificationService $notifications): void
    {
        $ledger = StudentFeeLedger::query()->find($this->ledgerId);

        if (!$ledger) {
            return;
        }

        $notifications->notifyStudentLedgerRecorded(
            $ledger,
            $this->subject,
            $this->headline,
            $this->extraLines
        );
    }
}
