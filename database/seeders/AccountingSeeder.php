<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\FinancialPeriod;
use App\Models\FinancialYear;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAccounts();
        $this->seedAprMarFinancialYearsAndPeriods();
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => 'AR_STUDENTS', 'name' => 'Accounts Receivable - Students', 'type' => 'asset'],
            ['code' => 'CASH_DRAWER', 'name' => 'Cash Drawer', 'type' => 'asset', 'is_cash' => true],
            ['code' => 'BANK_MAIN', 'name' => 'Bank - Main', 'type' => 'asset', 'is_bank' => true],
            ['code' => 'BANK_SECONDARY', 'name' => 'Bank - Secondary', 'type' => 'asset', 'is_bank' => true],
            ['code' => 'CLEARING_GATEWAY', 'name' => 'Clearing - Payment Gateway', 'type' => 'asset'],

            ['code' => 'INCOME_FEES', 'name' => 'Fee Income', 'type' => 'income'],
            ['code' => 'INCOME_TRANSPORT', 'name' => 'Transport Income', 'type' => 'income'],
            ['code' => 'INCOME_OTHER', 'name' => 'Other Income', 'type' => 'income'],
            ['code' => 'EXPENSE_OPERATING', 'name' => 'Operating Expense', 'type' => 'expense'],
            ['code' => 'CONTRA_SCHOLARSHIP', 'name' => 'Scholarships / Waivers (Contra Income)', 'type' => 'income'],
        ];

        foreach ($accounts as $row) {
            Account::firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'is_cash' => (bool) ($row['is_cash'] ?? false),
                    'is_bank' => (bool) ($row['is_bank'] ?? false),
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedAprMarFinancialYearsAndPeriods(): void
    {
        $today = Carbon::today();
        $startYear = $today->month >= 4 ? $today->year : ($today->year - 1);

        // Seed current FY and the next FY to avoid future date failures.
        $this->ensureAprMarFinancialYearExists($startYear);
        $this->ensureAprMarFinancialYearExists($startYear + 1);
    }

    private function ensureAprMarFinancialYearExists(int $startYear): void
    {
        $start = Carbon::create($startYear, 4, 1)->startOfDay();
        $end = Carbon::create($startYear + 1, 3, 31)->endOfDay();

        $code = sprintf('FY%d-%02d', $startYear, (int) (($startYear + 1) % 100));

        $year = FinancialYear::firstOrCreate(
            ['code' => $code],
            [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_closed' => false,
            ]
        );

        // Build 12 monthly periods from Apr..Mar.
        $cursor = $start->copy()->startOfMonth();
        for ($i = 1; $i <= 12; $i++) {
            $periodStart = $cursor->copy()->startOfMonth();
            $periodEnd = $cursor->copy()->endOfMonth();
            $label = $periodStart->format('M Y');

            FinancialPeriod::firstOrCreate(
                ['financial_year_id' => $year->id, 'month' => $i],
                [
                    'label' => $label,
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'is_locked' => false,
                ]
            );

            $cursor->addMonthNoOverflow();
        }
    }
}
