<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\FundTransferData;
use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records a move between the somiti's own accounts as pending (maker). The same idempotency key
 * twice returns the first transfer.
 */
final class RecordFundTransfer
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, FundTransferData $data): FundTransfer
    {
        Gate::forUser($actor)->authorize('create', FundTransfer::class);

        $existing = FundTransfer::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if ($existing !== null) {
            if ($existing->from_method !== $data->from || $existing->to_method !== $data->to || ! $existing->amount_poisha->equals($data->amount)) {
                throw DomainRuleViolation::because('transfers.errors.idempotency_conflict');
            }

            return $existing;
        }

        $this->assertValid($data);

        return $this->causer->withCauser($actor, fn (): FundTransfer => DB::transaction(function () use ($actor, $data): FundTransfer {
            $number = (int) DB::scalar("SELECT nextval('transfer_no_seq')");

            return FundTransfer::query()->create([
                'transfer_no' => sprintf('T-%05d', $number),
                'from_method' => $data->from,
                'to_method' => $data->to,
                'amount_poisha' => $data->amount,
                'charge_poisha' => $data->charge,
                'transferred_on' => $data->transferredOn->toDateString(),
                'reference' => $data->reference,
                'notes' => $data->notes,
                'attachment_path' => $data->attachmentPath,
                'status' => TransferStatus::Pending,
                'idempotency_key' => $data->idempotencyKey,
                'recorded_by' => $actor->id,
            ]);
        }, attempts: 3));
    }

    private function assertValid(FundTransferData $data): void
    {
        if ($data->from === $data->to) {
            throw DomainRuleViolation::because('transfers.errors.same_account');
        }

        if (! $data->amount->isPositive()) {
            throw DomainRuleViolation::because('transfers.errors.amount_positive');
        }

        if ($data->charge->isNegative()) {
            throw DomainRuleViolation::because('transfers.errors.charge_negative');
        }

        if ($data->transferredOn->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
            throw DomainRuleViolation::because('transfers.errors.future_date');
        }
    }
}
