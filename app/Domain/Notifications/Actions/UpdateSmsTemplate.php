<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Models\SmsTemplate;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Edits an SMS text. Only the template's own placeholders may be used, so a typo like {nmae}
 * is caught here instead of reaching members.
 */
final class UpdateSmsTemplate
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, SmsTemplate $template, string $bodyBn, string $bodyEn, bool $active): SmsTemplate
    {
        Gate::forUser($actor)->authorize('update', $template);

        foreach ([$bodyBn, $bodyEn] as $body) {
            if (trim($body) === '') {
                throw DomainRuleViolation::because('sms.errors.body_required');
            }

            preg_match_all('/\{([a-z_]+)\}/', $body, $matches);
            $unknown = array_diff($matches[1], $template->key->placeholders());

            if ($unknown !== []) {
                throw DomainRuleViolation::because('sms.errors.unknown_placeholder', ['placeholder' => '{'.reset($unknown).'}']);
            }
        }

        return $this->causer->withCauser($actor, fn (): SmsTemplate => DB::transaction(function () use ($template, $bodyBn, $bodyEn, $active): SmsTemplate {
            $template->forceFill(['body_bn' => trim($bodyBn), 'body_en' => trim($bodyEn), 'is_active' => $active])->save();

            return $template;
        }));
    }
}
