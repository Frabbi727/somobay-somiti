<?php

declare(strict_types=1);

namespace App\Domain\Exits\Actions;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Members\Models\Nominee;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W9 step 6: pays the settlement out by PV (Dr 2301 / Cr cash or bank). For a deceased member it is
 * split between the nominees by share_bps (largest remainder; Σ = settlement exactly).
 */
final class PayExit
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, MemberExit $exit, PaymentMethod $paidFrom, ?CarbonImmutable $on = null): MemberExit
    {
        $on ??= CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay();

        return $this->causer->withCauser($actor, fn (): MemberExit => DB::transaction(function () use ($actor, $exit, $paidFrom, $on): MemberExit {
            $locked = MemberExit::query()->whereKey($exit->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('pay', $locked);

            $net = $locked->net_poisha ?? throw DomainRuleViolation::because('exits.errors.not_approved');
            $member = $locked->member;
            $entryId = null;

            if ($net->isPositive()) {
                $payees = $this->payees($locked);
                $source = $this->accounts->byCode($paidFrom->accountCode());
                $this->funds->assertCovers($source, $net);

                $entry = ($this->post)($actor, new JournalEntryData(
                    type: VoucherType::Payment,
                    entryDate: $on,
                    narration: __('exits.narration.payout', ['member' => $member->member_no, 'number' => $locked->exit_no]),
                    lines: [
                        JournalLineData::debit($this->accounts->byCode(AccountCode::EXIT_PAYABLE), $net, $member->id),
                        JournalLineData::credit($source, $net),
                    ],
                    source: $locked,
                ));
                $entryId = $entry->id;

                $amounts = $net->allocate(array_map(fn (array $payee): int => $payee['share_bps'], $payees));

                foreach ($payees as $key => $payee) {
                    $locked->payouts()->create([
                        'nominee_id' => $payee['nominee_id'],
                        'payee' => $payee['name'],
                        'share_bps' => $payee['share_bps'],
                        'amount_poisha' => $amounts[$key],
                        'paid_from' => $paidFrom,
                    ]);
                }
            }

            $locked->update([
                'status' => ExitStatus::Paid,
                'paid_by' => $actor->id,
                'paid_at' => CarbonImmutable::now(),
                'payout_journal_entry_id' => $entryId,
            ]);

            return $locked;
        }, attempts: 3));
    }

    /**
     * @return list<array{nominee_id: int|null, name: string, share_bps: int}>
     */
    private function payees(MemberExit $exit): array
    {
        if (! $exit->reason_type->paysNominees()) {
            return [['nominee_id' => null, 'name' => $exit->member->name_bn.' / '.$exit->member->name_en, 'share_bps' => 10_000]];
        }

        $nominees = Nominee::query()->where('member_id', $exit->member_id)->orderBy('sort')->orderBy('id')->get();

        if ($nominees->isEmpty() || (int) $nominees->sum('share_bps') !== 10_000) {
            throw DomainRuleViolation::because('exits.errors.nominees');
        }

        return array_values($nominees->map(fn (Nominee $nominee): array => [
            'nominee_id' => $nominee->id,
            'name' => $nominee->name,
            'share_bps' => $nominee->share_bps,
        ])->all());
    }
}
