<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Services;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Notifications\Services\WorkflowNotifier;

/**
 * Tells everyone who may decide the current step that a registration waits for them (admin panel
 * bell, with a link to the application, in each approver's language). Sent after commit, so a
 * rolled-back decision notifies nobody.
 */
final class ApproverNotifier
{
    public function __construct(private readonly WorkflowNotifier $notifier) {}

    public function notifyStep(MemberApplication $application): void
    {
        if ($application->currentRole() === null) {
            return;
        }

        $this->notifier->waiting($application, 'decide');
    }
}
