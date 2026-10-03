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
    ],
    'log' => [
        'singular' => 'SMS',
        'plural' => 'SMS Log',
        'at' => 'Queued at',
        'to' => 'To',
        'body' => 'Message',
        'status' => 'Status',
    ],
    'status' => [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],
    'errors' => [
        'body_required' => 'Both texts are required.',
        'unknown_placeholder' => ':placeholder is not available for this message.',
    ],
];
