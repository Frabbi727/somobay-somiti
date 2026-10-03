<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Members\Events\MemberJoined;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsFormat;
use App\Domain\Notifications\Services\SmsSender;

final class SendWelcomeSms
{
    public function __construct(private readonly SmsSender $sms) {}

    public function handle(MemberJoined $event): void
    {
        $member = $event->member;

        $this->sms->template(SmsTemplateKey::Welcome, $member->mobile, [
            'name' => $member->name_bn,
            'member_no' => SmsFormat::digits($member->member_no),
            'portal_url' => url('/portal'),
        ], $member, 'welcome:'.$member->id, $member);
    }
}
