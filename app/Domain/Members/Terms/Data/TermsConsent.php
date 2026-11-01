<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Data;

use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;

/**
 * What the person agreed to: the rules version they saw, where they agreed and the retry key.
 */
final readonly class TermsConsent
{
    public function __construct(
        public int $version,
        public ConsentChannel $channel,
        public string $idempotencyKey,
        public ?string $ip = null,
    ) {}

    /**
     * @deprecated Task 9 replaces this with the member's explicit tick.
     */
    public static function implicitForCurrent(ConsentChannel $channel, string $idempotencyKey, ?string $ip = null): self
    {
        return new self((int) MembershipTerms::current()->version, $channel, $idempotencyKey, $ip);
    }
}
