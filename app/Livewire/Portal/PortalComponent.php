<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Base for signed-in portal pages: everything is scoped to the member behind the session.
 */
#[Layout('layouts.portal')]
abstract class PortalComponent extends Component
{
    /**
     * Livewire update requests skip the page's route middleware, so the language is set here too.
     */
    public function boot(): void
    {
        $user = Auth::user();

        if ($user instanceof User) {
            app()->setLocale($user->locale);
        }
    }

    protected function member(): Member
    {
        $user = Auth::user();
        $member = $user instanceof User ? app(PortalAccounts::class)->memberOf($user) : null;

        abort_if($member === null, 403);

        return $member;
    }
}
