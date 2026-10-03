<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class PortalSessionController extends Controller
{
    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['bn', 'en'], true), 404);

        $user = Auth::user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale])->save();
        }

        session(['portal_locale' => $locale]);

        return back();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
