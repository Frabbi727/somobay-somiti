<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\Models\SomitiProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public privacy policy for the member app and portal (the Play Store link). Bangla by
 * default, the society's language; ?lang=en for English. The society's own name and contact
 * details come from the society profile.
 */
final class PrivacyPolicyController extends Controller
{
    public function __invoke(Request $request): View
    {
        $locale = $request->query('lang') === 'en' ? 'en' : 'bn';
        app()->setLocale($locale);

        return view('privacy', [
            'locale' => $locale,
            'otherLocale' => $locale === 'bn' ? 'en' : 'bn',
            'profile' => SomitiProfile::current(),
        ]);
    }
}
