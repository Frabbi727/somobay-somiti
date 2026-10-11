<?php

declare(strict_types=1);

return [
    'length' => ':chars characters · :encoding · :parts SMS',
    'template' => [
        'singular' => 'SMS template',
        'plural' => 'SMS Templates',
        'key' => 'Message',
        'placeholders' => 'Placeholders you can use',
        'body_bn' => 'Text (Bangla)',
        'body_en' => 'Text (English)',
        'is_active' => 'Send this message',
        'parts' => 'Parts',
        'save_heading' => 'Save the ":key" message?',
        'welcome' => 'Welcome',
        'dues_generated' => 'Monthly dues notice',
        'payment_approved' => 'Payment received',
        'login_code' => 'Portal login code',
        'registration_returned' => 'Registration sent back',
        'registration_rejected' => 'Registration rejected',
        'payment_rejected' => 'Payment not accepted',
        'payment_reversed' => 'Payment cancelled',
    ],
    'log' => [
        'singular' => 'SMS',
        'plural' => 'SMS Log',
        'at' => 'Queued at',
        'to' => 'To',
        'body' => 'Message',
        'status' => 'Status',
        'resend' => 'Send again',
        'resend_help' => 'Try this failed message again, e.g. after the SMS balance is topped up',
        'resend_heading' => 'Send this message to :to again?',
        'resent' => 'The message is queued again.',
    ],
    'status' => [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],
    'errors' => [
        'body_required' => 'Both texts are required.',
        'not_failed' => 'Only a failed message can be sent again. Refresh the page to see its current status.',
        'unknown_placeholder' => ':placeholder is not available for this message.',
    ],
];
