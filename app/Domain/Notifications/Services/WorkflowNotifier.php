<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Reporting\RecordDescriptor;
use App\Enums\Role;
use App\Filament\Member\Pages\Dashboard as MemberDashboard;
use App\Filament\Member\Pages\Payments as MemberPayments;
use App\Filament\Member\Pages\RegistrationStatus;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Who hears about each step of an approval (docs/notification-matrix.md):
 * - a new item → everyone the policies let act on it (never "every admin");
 * - an outcome → whoever entered it (and, for a reversal, whoever approved it);
 * - a member's own payment → the member, in the portal and, when it is turned down, by SMS.
 *
 * Call these from inside the Action's transaction; nothing is sent unless it commits.
 */
final class WorkflowNotifier
{
    public function __construct(
        private readonly InAppNotifier $bell,
        private readonly SmsSender $sms,
        private readonly RecordDescriptor $describe,
    ) {}

    /**
     * A new item waits for someone to approve (or post, pay…) it.
     */
    public function waiting(Model $record, string $ability = 'approve', string $event = 'submitted'): void
    {
        $this->bell->send(
            key: $this->describe->type($record).'.'.$event,
            recipients: fn (): Collection => $this->whoCan($ability, $record),
            params: fn (): array => $this->describe->params($record),
            url: $this->describe->url($record),
            status: 'warning',
        );
    }

    /**
     * Tells the people who entered or approved an item what happened to it.
     *
     * @param  list<int|null>  $userIds
     */
    public function outcome(Model $record, string $event, array $userIds): void
    {
        $ids = array_values(array_unique(array_filter($userIds, fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return;
        }

        $this->bell->send(
            key: $this->describe->type($record).'.'.$event,
            recipients: fn (): Collection => User::query()->whereKey($ids)->get()->filter(fn (User $user): bool => $user->isStaff()),
            params: fn (): array => $this->describe->params($record),
            url: $this->describe->url($record),
            status: match ($event) {
                'approved', 'posted' => 'success',
                'rejected', 'reversed' => 'danger',
                default => 'warning',
            },
        );
    }

    /**
     * What happened to a payment, for the member who paid it and the staff involved.
     *
     * @param  'approved'|'rejected'|'reversed'  $event
     */
    public function paymentOutcome(Payment $payment, string $event): void
    {
        $this->outcome($payment, $event, $event === 'reversed'
            ? [$payment->recorded_by, $payment->approved_by]
            : [$payment->recorded_by]);

        $member = $payment->member;

        if ($member->user_id !== null) {
            $this->bell->send(
                key: 'member_payment.'.$event,
                recipients: fn (): Collection => User::query()->whereKey($member->user_id)->get(),
                params: fn (): array => $this->describe->params($payment),
                url: MemberPayments::getUrl(panel: 'member'),
                status: $event === 'approved' ? 'success' : 'danger',
            );
        }

        if ($event === 'approved') {
            return; // the receipt SMS already goes out (SendPaymentReceiptSms)
        }

        $this->sms->template(
            $event === 'rejected' ? SmsTemplateKey::PaymentRejected : SmsTemplateKey::PaymentReversed,
            $member->mobile,
            [
                'name' => $member->name_bn,
                'amount' => SmsFormat::money($payment->amount_poisha),
                'reason' => (string) ($event === 'rejected' ? $payment->rejection_reason : $payment->reversal_reason),
            ],
            $member,
            "payment:{$payment->id}:{$event}",
            $payment,
        );
    }

    /**
     * The applicant hears that their registration was approved or sent back (an SMS goes too).
     *
     * @param  'approved'|'returned'  $event
     */
    public function applicantOutcome(MemberApplication $application, string $event): void
    {
        $this->bell->send(
            key: 'applicant.'.$event,
            recipients: fn (): Collection => User::query()->whereKey($application->user_id)->get(),
            params: fn (): array => $this->describe->params($application),
            url: $event === 'approved' ? MemberDashboard::getUrl(panel: 'member') : RegistrationStatus::getUrl(panel: 'member'),
            status: $event === 'approved' ? 'success' : 'warning',
        );
    }

    /**
     * Roles or permissions changed: the president (and the super admins) should know.
     *
     * @param  'user_roles'|'role_permissions'  $event
     * @param  Closure(): array<string, string>  $values  built in each recipient's language
     */
    public function accessChanged(User $actor, string $event, Closure $values, string $url): void
    {
        $this->bell->send(
            key: 'access.'.$event,
            recipients: fn (): Collection => User::query()
                ->whereHas('roles', fn ($query) => $query->whereIn('name', [Role::President->value, Role::SuperAdmin->value]))
                ->whereKeyNot($actor->id)
                ->get(),
            params: fn (): array => ['actor' => $actor->name, ...$values()],
            url: $url,
            status: 'warning',
        );
    }

    /**
     * Active staff who may do $ability on the record as it is once the transaction has committed.
     *
     * @return Collection<int, User>
     */
    public function whoCan(string $ability, Model $record): Collection
    {
        $fresh = $record->fresh() ?? $record;

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', Role::staff()))
            ->whereNull('deactivated_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows($ability, $fresh))
            ->values();
    }
}
