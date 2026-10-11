<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FundTransfers\FundTransferResource;
use App\Filament\Resources\Investments\InvestmentResource;
use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\MemberExits\MemberExitResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\YearEnds\YearEndResource;
use App\Filament\Support\Display;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One description of every record that goes through an approval, shared by the bell messages
 * and the dashboard so both name and link a record the same way.
 */
final class RecordDescriptor
{
    /**
     * The kind of record, as used in translation keys ("payment", "rate_plan"…).
     */
    public function type(Model $record): string
    {
        return match (true) {
            $record instanceof Payment => 'payment',
            $record instanceof Expense => 'expense',
            $record instanceof FundTransfer => 'transfer',
            $record instanceof Investment => 'investment',
            $record instanceof RatePlan => 'rate_plan',
            $record instanceof YearEnd => 'year_end',
            $record instanceof MemberExit => 'exit',
            $record instanceof JournalDraft => 'journal_draft',
            $record instanceof MemberApplication => 'registration',
            default => throw new \LogicException('No notifications for '.$record::class),
        };
    }

    /**
     * Words describing the record, in the current language: reference, amount, party, reason.
     *
     * @return array<string, string>
     */
    public function params(Model $record): array
    {
        $record = $record->fresh() ?? $record;

        return match (true) {
            $record instanceof Payment => [
                'reference' => $record->member->displayName(),
                'amount' => Display::money($record->amount_poisha),
                'method' => $record->method->getLabel(),
                'reason' => (string) ($record->rejection_reason ?? $record->reversal_reason ?? ''),
            ],
            $record instanceof Expense => [
                'reference' => $record->expense_no,
                'amount' => Display::money($record->amount_poisha),
                'party' => Str::limit($record->payee ?? $record->description, 60),
                'reason' => (string) ($record->rejection_reason ?? $record->reversal_reason ?? ''),
            ],
            $record instanceof FundTransfer => [
                'reference' => $record->transfer_no,
                'amount' => Display::money($record->amount_poisha),
                'party' => $record->from_method->getLabel().' → '.$record->to_method->getLabel(),
                'reason' => (string) ($record->rejection_reason ?? $record->reversal_reason ?? ''),
            ],
            $record instanceof Investment => [
                'reference' => $record->investment_no,
                'amount' => Display::money($record->principal_poisha),
                'party' => $record->institution,
                'reason' => (string) ($record->rejection_reason ?? ''),
            ],
            $record instanceof RatePlan => [
                'reference' => $record->code,
                'amount' => Display::money($record->share_unit_poisha),
                'party' => Display::yearMonth($record->effective_from),
                'reason' => '',
            ],
            $record instanceof YearEnd => [
                'reference' => $record->fiscalYear->code,
                'amount' => Display::money($record->net_profit_poisha),
            ],
            $record instanceof MemberExit => [
                'reference' => $record->exit_no,
                'party' => $record->member->displayName(),
                'amount' => Display::money($record->net_poisha),
            ],
            $record instanceof JournalDraft => [
                'reference' => $record->voucher_type->getLabel().' #'.Display::digits($record->id),
                'amount' => Display::money($record->totalDebit()),
                'party' => Str::limit($record->narration, 60),
            ],
            $record instanceof MemberApplication => [
                'reference' => $record->name_bn ?? $record->name_en ?? $record->mobile,
                'party' => $record->mobile,
            ],
            default => [],
        };
    }

    /**
     * The staff page that shows the record.
     */
    public function url(Model $record): string
    {
        return match (true) {
            $record instanceof Payment => PaymentResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof Expense => ExpenseResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof FundTransfer => FundTransferResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof Investment => InvestmentResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof RatePlan => RatePlanResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof YearEnd => YearEndResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof MemberExit => MemberExitResource::getUrl('view', ['record' => $record], panel: 'admin'),
            $record instanceof JournalDraft => JournalDraftResource::getUrl(panel: 'admin'),
            $record instanceof MemberApplication => MemberApplicationResource::getUrl('view', ['record' => $record], panel: 'admin'),
            default => throw new \LogicException('No page for '.$record::class),
        };
    }
}
