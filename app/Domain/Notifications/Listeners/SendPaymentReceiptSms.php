<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Contributions\Events\PaymentApproved;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsFormat;
use App\Domain\Notifications\Services\SmsSender;
use App\Support\Money\Money;

/**
 * W3: tells the member the money was received, with the receipt number and "paid through".
 */
final class SendPaymentReceiptSms
{
    public function __construct(
        private readonly SmsSender $sms,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $payment = $event->payment->loadMissing(['member', 'journalEntry']);
        $member = $payment->member;
        $through = $payment->paid_through_after;

        $this->sms->template(SmsTemplateKey::PaymentApproved, $member->mobile, [
            'name' => $member->name_bn,
            'amount' => SmsFormat::money($payment->amount_poisha),
            'voucher' => SmsFormat::digits($payment->journalEntry->voucher_no ?? ''),
            'paid_through' => $through === null ? '—' : SmsFormat::month($through),
            'owed' => SmsFormat::money($payment->outstanding_after_poisha ?? Money::zero()),
        ], $member, 'payment:'.$payment->id, $payment);
    }
}
