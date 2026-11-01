<?php

declare(strict_types=1);

return [
    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],
    'channel' => [
        'portal' => 'Member portal',
        'app' => 'Mobile app',
        'office' => 'Office (paper form)',
    ],
    'office_confirm' => 'The member has read and agreed to the somiti rules (version :version)',
    'portal' => [
        'title' => 'Rules and declaration',
        'rules_heading' => 'Rules and declaration',
        'declaration_heading' => 'Declaration',
        'not_yet' => 'You have not accepted the current rules yet.',
        'please_read' => 'Please read the current rules and declaration, then agree to continue.',
        'agree' => "I have read and agree to the somiti's rules and declaration",
        'agree_required' => 'Tick the box to confirm you have read and agree.',
        'accept' => 'Agree and continue',
        'accepted' => 'Thank you. You have agreed to the rules.',
        'read_rules' => 'Read the rules',
        'profile_row' => 'Rules I accepted (version :version, :date)',
    ],
    'api' => [
        'accepted' => 'The rules were accepted.',
    ],
    'errors' => [
        'acceptance_required' => 'You need to accept the current rules before you can continue.',
        'text_required' => 'The rules and the declaration must be filled in, in both Bangla and English.',
        'not_draft' => 'Only a draft can be published; this version is already published.',
        'outdated' => 'This version of the rules has been replaced. Please read the current rules and agree again.',
        'idempotency_conflict' => 'This confirmation key was already used for another acceptance.',
    ],
];
