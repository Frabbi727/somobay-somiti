<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Support\Money\Money;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * A taka amount field. Accepts "1,234.50" or Bangla digits as text; the server parses it to
 * poisha and dehydrates it as a Money instance. No money math happens in the browser.
 */
final class MoneyInput extends TextInput
{
    protected bool $isNegativeAllowed = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->prefix('৳')
            ->inputMode('decimal')
            ->placeholder('0.00')
            ->formatStateUsing(fn (mixed $state): ?string => self::toFormState($state))
            ->rule(fn (MoneyInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                if ($value === null || $value === '') {
                    return;
                }

                $money = is_string($value) || is_int($value) ? Money::tryOfTaka((string) $value) : null;

                if ($money === null) {
                    $fail(__('money.validation.invalid'));

                    return;
                }

                if ($money->isNegative() && ! $component->isNegativeAllowed()) {
                    $fail(__('money.validation.negative'));
                }
            })
            ->dehydrateStateUsing(fn (mixed $state): ?Money => is_string($state) && trim($state) !== ''
                ? Money::ofTaka($state)
                : null);
    }

    public function allowNegative(bool $condition = true): static
    {
        $this->isNegativeAllowed = $condition;

        return $this;
    }

    public function isNegativeAllowed(): bool
    {
        return $this->isNegativeAllowed;
    }

    /**
     * Convert a hydrated value (Money from a cast, int poisha from serialization, or text) to form text.
     */
    private static function toFormState(mixed $state): ?string
    {
        return match (true) {
            $state instanceof Money => $state->toTakaString(),
            is_int($state) => Money::ofPoisha($state)->toTakaString(),
            is_string($state) => $state,
            default => null,
        };
    }
}
