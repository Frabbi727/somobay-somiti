<?php

declare(strict_types=1);

namespace App\Domain\Settings\Data;

use Carbon\CarbonImmutable;

final readonly class SomitiProfileData
{
    public function __construct(
        public string $nameBn,
        public string $nameEn,
        public ?string $registrationNo = null,
        public ?CarbonImmutable $registeredOn = null,
        public ?string $addressBn = null,
        public ?string $addressEn = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $logoPath = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $text = function (string $key) use ($data): ?string {
            $value = trim((string) ($data[$key] ?? ''));

            return $value === '' ? null : $value;
        };

        return new self(
            nameBn: (string) $text('name_bn'),
            nameEn: (string) $text('name_en'),
            registrationNo: $text('registration_no'),
            registeredOn: $text('registered_on') === null ? null : CarbonImmutable::parse((string) $text('registered_on')),
            addressBn: $text('address_bn'),
            addressEn: $text('address_en'),
            phone: $text('phone'),
            email: $text('email'),
            logoPath: $text('logo_path'),
        );
    }
}
