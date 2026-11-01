<?php

declare(strict_types=1);

namespace App\Domain\Geo\Services;

use App\Domain\Geo\Data\PostalAddress;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

final class AddressRules
{
    public function __construct(private readonly GeoDirectory $geo) {}

    /**
     * @param  string  $whoseKey  translation key of the label, e.g. members.address.present
     * @param  array<string, string|int>  $replace  extra placeholders for the label and the messages
     */
    public function assertValid(?PostalAddress $address, bool $required, string $whoseKey, array $replace = []): void
    {
        $replace = [...$replace, 'whose' => $this->label($whoseKey, $replace)];

        if ($address === null || $address->upazilaId <= 0 || $address->line === '') {
            if ($required || $address !== null) {
                throw DomainRuleViolation::because('members.errors.address_required', $replace);
            }

            return;
        }

        if ($address->unionId === null && $this->geo->upazilaHasUnions($address->upazilaId)) {
            throw DomainRuleViolation::because('members.errors.address_union_required', $replace);
        }

        $this->assertFormat($address, $whoseKey, $replace);
    }

    /**
     * Checks that never wait for a complete form: the union belongs to the upazila, the line fits.
     *
     * @param  array<string, string|int>  $replace
     */
    public function assertFormat(PostalAddress $address, string $whoseKey, array $replace = []): void
    {
        $replace = ['whose' => $this->label($whoseKey, $replace), ...$replace];

        if ($address->unionId !== null && ! $this->geo->unionBelongsTo($address->unionId, $address->upazilaId)) {
            throw DomainRuleViolation::because('members.errors.address_union_mismatch', $replace);
        }

        if (mb_strlen($address->line) > 255) {
            throw DomainRuleViolation::because('members.errors.address_line_too_long', $replace);
        }
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function label(string $whoseKey, array $replace): string
    {
        $label = trans($whoseKey, $replace);

        return is_string($label) ? $label : $whoseKey;
    }
}
