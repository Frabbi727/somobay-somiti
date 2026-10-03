<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Events\MonthlyDuesGenerated;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsFormat;
use App\Domain\Notifications\Services\SmsSender;
use App\Support\Money\Money;

/**
 * W2: "এই মাসের কিস্তি ৳X, শেষ তারিখ Y" — once per member per month, after advances were applied,
 * and only to members who still owe something for the month.
 */
final class SendDuesNoticeSms
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(MonthlyDuesGenerated $event): void
    {
        $month = $event->result->month;

        $dues = Due::query()
            ->where('month', $month->toDateString())
            ->where('status', DueStatus::Open)
            ->where('outstanding_poisha', '>', 0)
            ->whereHas('member', fn ($query) => $query->where('status', MemberStatus::Active))
            ->with('member')
            ->get()
            ->groupBy('member_id');

        foreach ($dues as $memberDues) {
            $member = $memberDues->first()?->member;

            if (! $member instanceof Member) {
                continue;
            }

            $this->sms->template(SmsTemplateKey::DuesGenerated, $member->mobile, [
                'name' => $member->name_bn,
                'month' => SmsFormat::month($month),
                'amount' => SmsFormat::money(Money::sum($memberDues->map(fn (Due $due): Money => $due->outstanding_poisha))),
                'due_date' => SmsFormat::date($memberDues->min('due_date')),
            ], $member, 'dues:'.$month.':'.$member->id);
        }
    }
}
