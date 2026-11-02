<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Data;

use App\Domain\Settings\Services\FinancialSettings;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Bps;

/**
 * The statutory appropriation percentages chosen for a year, checked against the legal bounds in
 * somiti.year_end (s.34; BR-20).
 */
final readonly class AppropriationRates
{
    /**
     * Appropriation line keys, in posting order, with the account each one credits.
     *
     * @var array<string, string>
     */
    public const array ACCOUNTS = [
        'reserve' => '3201',
        'development_fund' => '2211',
        'bad_debt_fund' => '3202',
        'other_funds' => '3203',
    ];

    public function __construct(
        public Bps $reserve,
        public Bps $developmentFund,
        public Bps $badDebtFund,
        public Bps $otherFunds,
    ) {}

    public static function defaults(): self
    {
        $settings = app(FinancialSettings::class)->current();

        return new self($settings->reserve, $settings->developmentFund, $settings->badDebtFund, $settings->otherFunds);
    }

    /**
     * Percent strings from the wizard ("15", "3", …).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $bps = fn (string $key, Bps $fallback): Bps => is_string($data[$key] ?? null) && trim((string) $data[$key]) !== ''
            ? Bps::ofPercent(trim((string) $data[$key]))
            : $fallback;

        $defaults = self::defaults();

        return new self(
            reserve: $bps('reserve', $defaults->reserve),
            developmentFund: $bps('development_fund', $defaults->developmentFund),
            badDebtFund: $bps('bad_debt_fund', $defaults->badDebtFund),
            otherFunds: $bps('other_funds', $defaults->otherFunds),
        );
    }

    /**
     * @return array<string, Bps>
     */
    public function byKey(): array
    {
        return [
            'reserve' => $this->reserve,
            'development_fund' => $this->developmentFund,
            'bad_debt_fund' => $this->badDebtFund,
            'other_funds' => $this->otherFunds,
        ];
    }

    public function assertLawful(): void
    {
        foreach ($this->byKey() as $key => $rate) {
            $min = (int) config("somiti.year_end.{$key}.min");
            $max = (int) config("somiti.year_end.{$key}.max");

            if ($key === 'bad_debt_fund' && app(FinancialSettings::class)->current()->financingSociety) {
                $min = max($min, (int) config('somiti.year_end.bad_debt_fund.financing_min'));
            }

            if ($rate->value < $min || $rate->value > $max) {
                throw DomainRuleViolation::because('year_end.errors.rate_bounds', [
                    'fund' => __('year_end.fund.'.$key),
                    'min' => Bps::of($min)->format(app()->getLocale()),
                    'max' => Bps::of($max)->format(app()->getLocale()),
                ]);
            }
        }
    }
}
