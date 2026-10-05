<?php

declare(strict_types=1);

return [
    'title' => 'Society profile',
    'subheading' => 'Your society\'s name, registration and logo, printed on every receipt and report.',
    'section' => [
        'identity' => 'Name and registration',
        'contact' => 'Address and contact',
        'logo' => 'Logo',
    ],
    'field' => [
        'name_bn' => 'Name (Bangla)',
        'name_en' => 'Name (English)',
        'registration_no' => 'Registration number',
        'registered_on' => 'Registered on',
        'address_bn' => 'Address (Bangla)',
        'address_en' => 'Address (English)',
        'phone' => 'Phone',
        'email' => 'Email',
    ],
    'logo_help' => 'PNG, JPG or WebP, up to 512 KB. A square logo looks best.',
    'save' => 'Save profile',
    'confirm' => 'Save these society details?',
    'saved' => 'Society profile saved.',
    'registration' => 'Reg. no. :no',
    'errors' => [
        'names_required' => 'Enter the society\'s name in both Bangla and English.',
        'phone_invalid' => 'Enter a valid phone number.',
        'email_invalid' => 'Enter a valid email address.',
    ],
];
