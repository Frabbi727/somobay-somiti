<?php

declare(strict_types=1);

namespace App\Domain\Governance\Data;

use App\Domain\Governance\Enums\Majority;
use App\Domain\Governance\Enums\ResolutionSubject;

final readonly class ResolutionData
{
    public function __construct(
        public ResolutionSubject $subject,
        public string $title,
        public string $body,
        public Majority $majority = Majority::Simple,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $subject = $data['subject'] ?? null;
        $majority = $data['majority'] ?? null;

        return new self(
            subject: $subject instanceof ResolutionSubject ? $subject : ResolutionSubject::from((string) $subject),
            title: trim((string) ($data['title'] ?? '')),
            body: trim((string) ($data['body'] ?? '')),
            majority: $majority instanceof Majority ? $majority : (Majority::tryFrom((string) $majority) ?? Majority::Simple),
        );
    }
}
