<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Models\SmsTemplate;
use App\Domain\Settings\Models\SomitiProfile;

/**
 * Fills a template's {placeholders}. Callers pass values already formatted for the locale.
 */
final class SmsComposer
{
    /**
     * @param  array<string, string>  $values
     */
    public function compose(SmsTemplateKey $key, array $values, string $locale = 'bn'): ?string
    {
        $template = SmsTemplate::query()->where('key', $key)->where('is_active', true)->first();

        if ($template === null) {
            return null;
        }

        return $this->fill($locale === 'en' ? $template->body_en : $template->body_bn, [
            'somiti' => SomitiProfile::current()->displayName($locale),
            ...$values,
        ]);
    }

    /**
     * @param  array<string, string>  $values
     */
    public function fill(string $body, array $values): string
    {
        $replacements = [];

        foreach ($values as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return trim(strtr($body, $replacements));
    }
}
