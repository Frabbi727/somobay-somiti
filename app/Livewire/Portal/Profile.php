<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

final class Profile extends PortalComponent
{
    public string $password = '';

    public string $password_confirmation = '';

    public ?string $saved = null;

    public function setPassword(): void
    {
        $this->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $user->forceFill(['password' => $this->password])->save();
        $this->reset(['password', 'password_confirmation']);
        $this->saved = __('portal.profile.password_saved');
    }

    public function render(): View
    {
        return view('livewire.portal.profile', [
            'member' => $this->member()->load('nominees'),
        ])->title(__('portal.nav.profile'));
    }
}
