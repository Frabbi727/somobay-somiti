<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\Models\Account;
use Carbon\CarbonImmutable;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('somiti:demo {--fresh : Wipe the database first (migrate:fresh)}')]
#[Description('Fill a LOCAL database with a realistic demo somiti and one login per role (never in production)')]
final class DemoCommand extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('The demo data is for local testing only.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);

            // migrate:fresh drops tables but not sequences; start the demo's numbers at 1 again.
            foreach (['member_no_seq', 'expense_no_seq', 'transfer_no_seq', 'investment_no_seq', 'meeting_no_seq', 'resolution_no_seq', 'exit_no_seq'] as $sequence) {
                DB::statement("ALTER SEQUENCE IF EXISTS {$sequence} RESTART WITH 1");
            }
        }

        $this->info('Building the demo somiti (July 2025 → today)…');
        $this->call('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true]);

        $this->newLine();
        $this->info('Staff logins (password: '.LocalDemoSeeder::PASSWORD.') at /admin');
        $this->table(['Email', 'Name', 'Role'], array_map(
            fn (string $email, array $user): array => [$email, $user[0], $user[1]->getLabel()],
            array_keys(LocalDemoSeeder::STAFF),
            LocalDemoSeeder::STAFF,
        ));
        $this->line('Members log in at /portal with their mobile (01711000001 … 01711000006) and the same password.');
        $this->line('Sample bank statement to import: '.$this->writeBankStatement());

        return self::SUCCESS;
    }

    /**
     * A bank statement CSV built from the demo's real bank movements, plus a bank charge the books do
     * not have yet — for trying Statement reconciliation.
     */
    private function writeBankStatement(): string
    {
        $bank = Account::query()->where('code', '1111')->value('id');

        $rows = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $bank)
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->get(['e.entry_date', 'e.narration', 'e.voucher_no', 'l.debit_poisha', 'l.credit_poisha']);

        $balance = 0;
        $lines = ['Txn Date,Particulars,Cheque No,Withdrawal,Deposit,Balance'];
        $format = fn (int $poisha): string => number_format(intdiv($poisha, 100)).'.'.str_pad((string) ($poisha % 100), 2, '0', STR_PAD_LEFT);

        foreach ($rows as $row) {
            $balance += (int) $row->debit_poisha - (int) $row->credit_poisha;
            $lines[] = implode(',', [
                CarbonImmutable::parse($row->entry_date)->format('d/m/Y'),
                '"'.str_replace('"', "'", mb_substr((string) $row->narration, 0, 40)).'"',
                '',
                (int) $row->credit_poisha > 0 ? '"'.$format((int) $row->credit_poisha).'"' : '',
                (int) $row->debit_poisha > 0 ? '"'.$format((int) $row->debit_poisha).'"' : '',
                '"'.$format($balance).'"',
            ]);
        }

        $balance -= 11500;
        $lines[] = CarbonImmutable::now('Asia/Dhaka')->subDay()->format('d/m/Y').',"SMS ALERT CHARGE + VAT",,"115.00",,"'.$format($balance).'"';

        Storage::disk('local')->put('demo/bank-statement.csv', implode("\n", $lines)."\n");

        return Storage::disk('local')->path('demo/bank-statement.csv');
    }
}
