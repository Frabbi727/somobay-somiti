<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Contributions\Events\PaymentApproved;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsFormat;
use App\Domain\Notifications\Services\SmsSender;

/**
 * W3: tells the member the money was received, with the receipt number and "paid through".
 */
final class SendPaymentReceiptSms
{
    public function __construct(
        private readonly SmsSender $sms,
        private readonly PaidThroughCalculator $paidThrough,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $payment = $event->payment->loadMissing(['member', 'journalEntry']);
        $member = $payment->member;
        $through = $this->paidThrough->for($member);

        $this->sms->template(SmsTemplateKey::PaymentApproved, $member->mobile, [
            'name' => $member->name_bn,
            'amount' => SmsFormat::money($payment->amount_poisha),
            'voucher' => SmsFormat::digits($payment->journalEntry->voucher_no ?? ''),
            'paid_through' => $through === null ? '—' : SmsFormat::month($through),
        ], $member, 'payment:'.$payment->id, $payment);
    }
}
