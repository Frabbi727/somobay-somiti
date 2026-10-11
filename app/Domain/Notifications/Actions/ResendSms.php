<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Jobs\SendSmsJob;
use App\Domain\Notifications\Models\SmsMessage;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Tries a failed SMS again (for example after the SMS balance was topped up). The same logged
 * message is queued again, so it can still only be delivered once. Login codes are never resent:
 * they have expired by then.
 */
final class ResendSms
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, SmsMessage $message): SmsMessage
    {
        return $this->causer->withCauser($actor, fn (): SmsMessage => DB::transaction(function () use ($actor, $message): SmsMessage {
            $locked = SmsMessage::query()->whereKey($message->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SmsStatus::Failed) {
                throw DomainRuleViolation::because('sms.errors.not_failed');
            }

            Gate::forUser($actor)->authorize('resend', $locked);

            $locked->forceFill(['status' => SmsStatus::Queued, 'error' => null])->save();
            SendSmsJob::dispatch($locked->id)->afterCommit();

            return $locked;
        }, attempts: 3));
    }
}
