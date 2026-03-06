<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Enrollment;
use App\Models\FinancialPeriod;
use App\Models\FinancialYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StudentFeeLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    private function toMinor(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromMinor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    public function resolveFinancialYear(CarbonInterface $date): FinancialYear
    {
        $year = FinancialYear::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();

        if (!$year) {
            throw ValidationException::withMessages([
                'posted_at' => 'No financial year found for the selected date.',
            ]);
        }

        if ((bool) $year->is_closed) {
            throw ValidationException::withMessages([
                'posted_at' => 'Financial year is closed. Posting is locked.',
            ]);
        }

        return $year;
    }

    public function resolveFinancialPeriod(FinancialYear $year, CarbonInterface $date): FinancialPeriod
    {
        $period = FinancialPeriod::query()
            ->where('financial_year_id', $year->id)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();

        if (!$period) {
            throw ValidationException::withMessages([
                'posted_at' => 'No financial period found for the selected date.',
            ]);
        }

        if ((bool) $period->is_locked) {
            throw ValidationException::withMessages([
                'posted_at' => 'Financial period is locked. Backdating is not allowed.',
            ]);
        }

        return $period;
    }

    public function accountByCode(string $code): Account
    {
        $account = Account::where('code', $code)->first();
        if (!$account) {
            throw ValidationException::withMessages([
                'account' => "Account code '{$code}' is not configured.",
            ]);
        }
        if (!(bool) $account->is_active) {
            throw ValidationException::withMessages([
                'account' => "Account '{$code}' is inactive.",
            ]);
        }
        return $account;
    }

    /**
     * Creates a balanced journal entry and writes a StudentFeeLedger projection row for the AR leg.
     *
     * @param array<int, array{account: Account, debit: float, credit: float, enrollment?: Enrollment|null, meta?: array|null}> $lines
     */
    public function postArJournal(
        CarbonInterface $entryDate,
        ?string $sourceType,
        ?int $sourceId,
        string $narration,
        Enrollment $enrollment,
        array $lines,
        string $arReferenceType,
        ?int $arReferenceId,
        string $arTransactionType,
        float $arAmount
    ): array {
        return DB::transaction(function () use (
            $entryDate,
            $sourceType,
            $sourceId,
            $narration,
            $enrollment,
            $lines,
            $arReferenceType,
            $arReferenceId,
            $arTransactionType,
            $arAmount
        ) {
            $year = $this->resolveFinancialYear($entryDate);
            $period = $this->resolveFinancialPeriod($year, $entryDate);

            $debitsMinor = 0;
            $creditsMinor = 0;
            foreach ($lines as $line) {
                $debitsMinor += $this->toMinor($line['debit'] ?? 0);
                $creditsMinor += $this->toMinor($line['credit'] ?? 0);
            }

            if ($debitsMinor !== $creditsMinor) {
                throw ValidationException::withMessages([
                    'lines' => 'Journal entry is not balanced.',
                ]);
            }

            $actorId = Auth::id();

            $entry = JournalEntry::create([
                'financial_year_id' => $year->id,
                'financial_period_id' => $period->id,
                'entry_date' => $entryDate->toDateString(),
                'posted_at' => now(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'narration' => $narration,
                'created_by' => $actorId,
                'reversal_of_journal_entry_id' => null,
            ]);

            /** @var JournalLine|null $arLine */
            $arLine = null;

            foreach ($lines as $line) {
                $lineEnrollment = $line['enrollment'] ?? null;
                $lineDebitMinor = $this->toMinor($line['debit'] ?? 0);
                $lineCreditMinor = $this->toMinor($line['credit'] ?? 0);
                $journalLine = JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account']->id,
                    'debit' => $this->fromMinor($lineDebitMinor),
                    'credit' => $this->fromMinor($lineCreditMinor),
                    'enrollment_id' => $lineEnrollment?->id,
                    'student_id' => $lineEnrollment?->student_id,
                    'meta' => $line['meta'] ?? null,
                ]);

                if ($lineEnrollment && (int) $lineEnrollment->id === (int) $enrollment->id) {
                    $arLine = $journalLine;
                }
            }

            $ledger = StudentFeeLedger::create([
                'enrollment_id' => $enrollment->id,
                'financial_year_id' => $year->id,
                'financial_period_id' => $period->id,
                'transaction_type' => $arTransactionType,
                'reference_type' => $arReferenceType,
                'reference_id' => $arReferenceId,
                'amount' => $this->fromMinor($this->toMinor($arAmount)),
                'posted_by' => $actorId,
                'posted_at' => $entryDate,
                'narration' => $narration,
                'is_reversal' => false,
                'reversal_of' => null,
                'journal_entry_id' => $entry->id,
                'journal_line_id' => $arLine?->id,
            ]);

            return [
                'journal_entry' => $entry,
                'student_fee_ledger' => $ledger,
            ];
        });
    }

    public function postTransportCharge(Enrollment $enrollment, int $cycleId, float $amount, CarbonInterface $entryDate): array
    {
        $ar = $this->accountByCode(config('accounting.accounts.ar_students'));
        $income = $this->accountByCode(config('accounting.accounts.income_transport'));

        return $this->postArJournal(
            $entryDate,
            'transport_cycle',
            $cycleId,
            'Transport charge generated',
            $enrollment,
            [
                ['account' => $ar, 'debit' => $amount, 'credit' => 0, 'enrollment' => $enrollment, 'meta' => ['reference' => 'transport']],
                ['account' => $income, 'debit' => 0, 'credit' => $amount, 'meta' => ['reference' => 'transport']],
            ],
            'transport',
            $cycleId,
            'debit',
            $amount
        );
    }

    public function postSpecialFee(Enrollment $enrollment, float $amount, string $narration, CarbonInterface $entryDate): array
    {
        $ar = $this->accountByCode(config('accounting.accounts.ar_students'));
        $income = $this->accountByCode(config('accounting.accounts.income_other'));

        return $this->postArJournal(
            $entryDate,
            'special_fee',
            null,
            $narration,
            $enrollment,
            [
                ['account' => $ar, 'debit' => $amount, 'credit' => 0, 'enrollment' => $enrollment, 'meta' => ['reference' => 'special_fee']],
                ['account' => $income, 'debit' => 0, 'credit' => $amount, 'meta' => ['reference' => 'special_fee']],
            ],
            'special_fee',
            null,
            'debit',
            $amount
        );
    }

    public function postPaymentReceived(
        Enrollment $enrollment,
        int $paymentId,
        float $amount,
        string $paymentMethod,
        CarbonInterface $entryDate,
        string $narration
    ): array {
        $ar = $this->accountByCode(config('accounting.accounts.ar_students'));
        $accountCode = config('accounting.payment_method_accounts.' . $paymentMethod, config('accounting.accounts.bank_main'));
        $cashBank = $this->accountByCode($accountCode);

        return $this->postArJournal(
            $entryDate,
            'payment',
            $paymentId,
            $narration,
            $enrollment,
            [
                ['account' => $cashBank, 'debit' => $amount, 'credit' => 0, 'meta' => ['payment_method' => $paymentMethod]],
                ['account' => $ar, 'debit' => 0, 'credit' => $amount, 'enrollment' => $enrollment, 'meta' => ['payment_method' => $paymentMethod]],
            ],
            'payment',
            $paymentId,
            'credit',
            $amount
        );
    }

    public function postReceiptReceived(
        Enrollment $enrollment,
        int $receiptId,
        float $amount,
        string $paymentMethod,
        CarbonInterface $entryDate,
        string $narration
    ): array {
        $ar = $this->accountByCode(config('accounting.accounts.ar_students'));
        $accountCode = config('accounting.payment_method_accounts.' . $paymentMethod, config('accounting.accounts.bank_main'));
        $cashBank = $this->accountByCode($accountCode);

        return $this->postArJournal(
            $entryDate,
            'receipt',
            $receiptId,
            $narration,
            $enrollment,
            [
                ['account' => $cashBank, 'debit' => $amount, 'credit' => 0, 'meta' => ['payment_method' => $paymentMethod]],
                ['account' => $ar, 'debit' => 0, 'credit' => $amount, 'enrollment' => $enrollment, 'meta' => ['payment_method' => $paymentMethod]],
            ],
            'receipt',
            $receiptId,
            'credit',
            $amount
        );
    }

    public function postRefundPaid(
        Enrollment $enrollment,
        int $refundPaymentId,
        float $amount,
        string $paymentMethod,
        CarbonInterface $entryDate,
        string $narration
    ): array {
        $ar = $this->accountByCode(config('accounting.accounts.ar_students'));
        $accountCode = config('accounting.payment_method_accounts.' . $paymentMethod, config('accounting.accounts.bank_main'));
        $cashBank = $this->accountByCode($accountCode);

        return $this->postArJournal(
            $entryDate,
            'refund',
            $refundPaymentId,
            $narration,
            $enrollment,
            [
                ['account' => $ar, 'debit' => $amount, 'credit' => 0, 'enrollment' => $enrollment, 'meta' => ['payment_method' => $paymentMethod]],
                ['account' => $cashBank, 'debit' => 0, 'credit' => $amount, 'meta' => ['payment_method' => $paymentMethod]],
            ],
            'refund',
            $refundPaymentId,
            'debit',
            $amount
        );
    }

    public function reverseJournalEntry(int $journalEntryId, string $reason, CarbonInterface $entryDate): array
    {
        return DB::transaction(function () use ($journalEntryId, $reason, $entryDate) {
            $original = JournalEntry::with('lines')->findOrFail($journalEntryId);
            if ($original->reversal()->exists()) {
                throw ValidationException::withMessages([
                    'journal_entry_id' => 'Journal entry already reversed.',
                ]);
            }

            $year = $this->resolveFinancialYear($entryDate);
            $period = $this->resolveFinancialPeriod($year, $entryDate);

            $actorId = Auth::id();

            $reversal = JournalEntry::create([
                'financial_year_id' => $year->id,
                'financial_period_id' => $period->id,
                'entry_date' => $entryDate->toDateString(),
                'posted_at' => now(),
                'source_type' => 'reversal',
                'source_id' => $original->id,
                'narration' => 'Reversal: ' . $reason,
                'created_by' => $actorId,
                'reversal_of_journal_entry_id' => $original->id,
            ]);

            $projectedLedgers = [];

            foreach ($original->lines as $line) {
                $reversalDebitMinor = $this->toMinor($line->credit);
                $reversalCreditMinor = $this->toMinor($line->debit);
                $reversalLine = JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'debit' => $this->fromMinor($reversalDebitMinor),
                    'credit' => $this->fromMinor($reversalCreditMinor),
                    'enrollment_id' => $line->enrollment_id,
                    'student_id' => $line->student_id,
                    'meta' => array_merge((array) ($line->meta ?? []), ['reversal_of_line_id' => $line->id]),
                ]);

                // Projection: only create student_fee_ledger rows for AR dimensioned lines.
                if ($line->enrollment_id) {
                    $enrollmentId = (int) $line->enrollment_id;
                    $transactionType = $reversalDebitMinor > 0 ? 'debit' : 'credit';
                    $amountMinor = $reversalDebitMinor > 0 ? $reversalDebitMinor : $reversalCreditMinor;

                    $projectedLedgers[] = StudentFeeLedger::create([
                        'enrollment_id' => $enrollmentId,
                        'financial_year_id' => $year->id,
                        'financial_period_id' => $period->id,
                        'transaction_type' => $transactionType,
                        'reference_type' => 'manual',
                        'reference_id' => $original->id,
                        'amount' => $this->fromMinor($amountMinor),
                        'posted_by' => $actorId,
                        'posted_at' => $entryDate,
                        'narration' => 'Reversal: ' . $reason,
                        'is_reversal' => true,
                        'reversal_of' => null,
                        'journal_entry_id' => $reversal->id,
                        'journal_line_id' => $reversalLine->id,
                    ]);
                }
            }

            return [
                'journal_entry' => $reversal,
                'student_fee_ledgers' => $projectedLedgers,
            ];
        });
    }

    /**
     * @param array<int, array{account: Account, debit: float, credit: float, meta?: array|null}> $lines
     */
    private function postGeneralJournal(
        CarbonInterface $entryDate,
        ?string $sourceType,
        ?int $sourceId,
        string $narration,
        array $lines
    ): JournalEntry {
        return DB::transaction(function () use ($entryDate, $sourceType, $sourceId, $narration, $lines) {
            $year = $this->resolveFinancialYear($entryDate);
            $period = $this->resolveFinancialPeriod($year, $entryDate);

            $debitsMinor = 0;
            $creditsMinor = 0;
            foreach ($lines as $line) {
                $debitsMinor += $this->toMinor($line['debit'] ?? 0);
                $creditsMinor += $this->toMinor($line['credit'] ?? 0);
            }

            if ($debitsMinor !== $creditsMinor) {
                throw ValidationException::withMessages([
                    'lines' => 'Journal entry is not balanced.',
                ]);
            }

            $entry = JournalEntry::create([
                'financial_year_id' => $year->id,
                'financial_period_id' => $period->id,
                'entry_date' => $entryDate->toDateString(),
                'posted_at' => now(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'narration' => $narration,
                'created_by' => Auth::id(),
                'reversal_of_journal_entry_id' => null,
            ]);

            foreach ($lines as $line) {
                $lineDebitMinor = $this->toMinor($line['debit'] ?? 0);
                $lineCreditMinor = $this->toMinor($line['credit'] ?? 0);
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account']->id,
                    'debit' => $this->fromMinor($lineDebitMinor),
                    'credit' => $this->fromMinor($lineCreditMinor),
                    'enrollment_id' => null,
                    'student_id' => null,
                    'meta' => $line['meta'] ?? null,
                ]);
            }

            return $entry;
        });
    }

    public function postExpensePaid(
        int $expenseId,
        float $amount,
        string $paymentAccountCode,
        string $expenseAccountCode,
        CarbonInterface $entryDate,
        string $narration
    ): JournalEntry {
        $cashOrBank = $this->accountByCode($paymentAccountCode);
        $expense = $this->accountByCode($expenseAccountCode);

        return $this->postGeneralJournal(
            $entryDate,
            'expense',
            $expenseId,
            $narration,
            [
                ['account' => $expense, 'debit' => $amount, 'credit' => 0, 'meta' => ['reference' => 'expense']],
                ['account' => $cashOrBank, 'debit' => 0, 'credit' => $amount, 'meta' => ['reference' => 'expense']],
            ]
        );
    }

    public function postExpenseReversal(
        int $expenseId,
        float $amount,
        string $paymentAccountCode,
        string $expenseAccountCode,
        CarbonInterface $entryDate,
        string $narration
    ): JournalEntry {
        $cashOrBank = $this->accountByCode($paymentAccountCode);
        $expense = $this->accountByCode($expenseAccountCode);

        return $this->postGeneralJournal(
            $entryDate,
            'expense_reversal',
            $expenseId,
            $narration,
            [
                ['account' => $cashOrBank, 'debit' => $amount, 'credit' => 0, 'meta' => ['reference' => 'expense_reversal']],
                ['account' => $expense, 'debit' => 0, 'credit' => $amount, 'meta' => ['reference' => 'expense_reversal']],
            ]
        );
    }
}
