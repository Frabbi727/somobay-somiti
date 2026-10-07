<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

use Filament\Support\Contracts\HasLabel;

enum SmsTemplateKey: string implements HasLabel
{
    case Welcome = 'welcome';
    case DuesGenerated = 'dues_generated';
    case PaymentApproved = 'payment_approved';
    case LoginCode = 'login_code';
    case RegistrationReturned = 'registration_returned';
    case RegistrationRejected = 'registration_rejected';

    /**
     * Placeholders the template may use.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::Welcome => ['name', 'member_no', 'somiti', 'portal_url'],
            self::DuesGenerated => ['name', 'month', 'amount', 'due_date', 'somiti'],
            self::PaymentApproved => ['name', 'amount', 'voucher', 'paid_through', 'somiti'],
            self::LoginCode => ['code', 'minutes', 'somiti'],
            self::RegistrationReturned => ['reason', 'somiti', 'portal_url'],
            self::RegistrationRejected => ['reason', 'somiti'],
        };
    }

    public function getLabel(): string
    {
        return __('sms.template.'.$this->value);
    }
}
