<?php

declare(strict_types=1);

namespace App\Domain\Settings\Data;

use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\YearEnd\Enums\StatutoryBase;
use App\Filament\Support\Display;
use App\Support\Money\Bps;
use App\Support\Money\Money;

/**
 * T-19: one complete set of the financial settings the committee may change. Defaults come from
 * config/somiti.php; approved versions are stored as JSON with money in poisha and rates in bps.
 */
final readonly class FinancialSettingsData
{
    /**
     * Subjects whose approval checks for a linked resolution (RequiredResolutions::assertSatisfied).
     */
    public const array RESOLUTION_SUBJECTS = [ResolutionSubject::RatePlan, ResolutionSubject::Investment, ResolutionSubject::YearEnd, ResolutionSubject::Exit];

    /**
     * Keys that change how a year-end is calculated.
     */
    public const array YEAR_END_KEYS = ['financing_society', 'statutory_base', 'reserve', 'development_fund', 'bad_debt_fund', 'other_funds'];

    /**
     * @param  list<ResolutionSubject>  $resolutionRequiredFor
     */
    public function __construct(
        public Money $expensePresidentThreshold,
        public Money $advanceRefundPresidentThreshold,
        public int $approvalReminderDays,
        public Bps $quorumCommittee,
        public Bps $quorumGeneral,
        public Bps $quorumSpecialGeneral,
        public array $resolutionRequiredFor,
        public bool $financingSociety,
        public StatutoryBase $statutoryBase,
        public Bps $reserve,
        public Bps $developmentFund,
        public Bps $badDebtFund,
        public Bps $otherFunds,
    ) {}

    public static function fromConfig(): self
    {
        return self::fromStored([]);
    }

    /**
     * Stored values over the config defaults, so a key added later falls back to its default.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $int = fn (string $key, mixed $default): int => is_int($stored[$key] ?? null) ? $stored[$key] : (int) (is_numeric($default) ? $default : 0);
        $subjects = is_array($stored['resolution_required_for'] ?? null) ? $stored['resolution_required_for'] : (array) config('somiti.require_resolution_for');
        $base = StatutoryBase::tryFrom(is_string($stored['statutory_base'] ?? null) ? $stored['statutory_base'] : (string) config('somiti.year_end.statutory_base'));
        $financing = is_bool($stored['financing_society'] ?? null) ? $stored['financing_society'] : (bool) config('somiti.year_end.financing_society');
        $badDebtDefault = (int) config('somiti.year_end.bad_debt_fund.default');

        if ($financing && ! isset($stored['bad_debt_fund'])) {
            $badDebtDefault = max($badDebtDefault, (int) config('somiti.year_end.bad_debt_fund.financing_min'));
        }

        return new self(
            expensePresidentThreshold: Money::ofPoisha($int('expense_president_threshold', config('somiti.expense_president_threshold_poisha'))),
            advanceRefundPresidentThreshold: Money::ofPoisha($int('advance_refund_president_threshold', config('somiti.advance_refund_president_threshold_poisha'))),
            approvalReminderDays: $int('approval_reminder_days', config('somiti.approval_reminder_days')),
            quorumCommittee: Bps::of($int('quorum_committee', config('somiti.quorum_bps.committee', 5001))),
            quorumGeneral: Bps::of($int('quorum_general', config('somiti.quorum_bps.general', 5001))),
            quorumSpecialGeneral: Bps::of($int('quorum_special_general', config('somiti.quorum_bps.special_general', 5001))),
            resolutionRequiredFor: self::subjects($subjects),
            financingSociety: $financing,
            statutoryBase: $base ?? StatutoryBase::NetProfit,
            reserve: Bps::of($int('reserve', config('somiti.year_end.reserve.default'))),
            developmentFund: Bps::of($int('development_fund', config('somiti.year_end.development_fund.default'))),
            badDebtFund: Bps::of($int('bad_debt_fund', $badDebtDefault)),
            otherFunds: Bps::of($int('other_funds', config('somiti.year_end.other_funds.default'))),
        );
    }

    /**
     * @param  array<string, mixed>  $form  taka and percent strings from the settings page
     */
    public static function fromForm(array $form): self
    {
        $text = fn (string $key): string => is_scalar($form[$key] ?? null) ? trim((string) $form[$key]) : '';
        $money = fn (string $key): Money => ($form[$key] ?? null) instanceof Money ? $form[$key] : Money::ofTaka($text($key) === '' ? '0' : $text($key));
        $percent = fn (string $key): Bps => Bps::ofPercent($text($key) === '' ? '0' : $text($key));
        $base = $form['statutory_base'] ?? null;

        return new self(
            expensePresidentThreshold: $money('expense_president_threshold'),
            advanceRefundPresidentThreshold: $money('advance_refund_president_threshold'),
            approvalReminderDays: (int) $text('approval_reminder_days'),
            quorumCommittee: $percent('quorum_committee'),
            quorumGeneral: $percent('quorum_general'),
            quorumSpecialGeneral: $percent('quorum_special_general'),
            resolutionRequiredFor: self::subjects(is_array($form['resolution_required_for'] ?? null) ? $form['resolution_required_for'] : []),
            financingSociety: (bool) ($form['financing_society'] ?? false),
            statutoryBase: $base instanceof StatutoryBase ? $base : StatutoryBase::from($text('statutory_base')),
            reserve: $percent('reserve'),
            developmentFund: $percent('development_fund'),
            badDebtFund: $percent('bad_debt_fund'),
            otherFunds: $percent('other_funds'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toForm(): array
    {
        return [
            'expense_president_threshold' => $this->expensePresidentThreshold->toTakaString(),
            'advance_refund_president_threshold' => $this->advanceRefundPresidentThreshold->toTakaString(),
            'approval_reminder_days' => (string) $this->approvalReminderDays,
            'quorum_committee' => $this->quorumCommittee->toPercentString(),
            'quorum_general' => $this->quorumGeneral->toPercentString(),
            'quorum_special_general' => $this->quorumSpecialGeneral->toPercentString(),
            'resolution_required_for' => array_map(fn (ResolutionSubject $subject): string => $subject->value, $this->resolutionRequiredFor),
            'financing_society' => $this->financingSociety,
            'statutory_base' => $this->statutoryBase->value,
            'reserve' => $this->reserve->toPercentString(),
            'development_fund' => $this->developmentFund->toPercentString(),
            'bad_debt_fund' => $this->badDebtFund->toPercentString(),
            'other_funds' => $this->otherFunds->toPercentString(),
        ];
    }

    /**
     * @return array<string, int|bool|string|list<string>>
     */
    public function toStored(): array
    {
        return [
            'expense_president_threshold' => $this->expensePresidentThreshold->poisha,
            'advance_refund_president_threshold' => $this->advanceRefundPresidentThreshold->poisha,
            'approval_reminder_days' => $this->approvalReminderDays,
            'quorum_committee' => $this->quorumCommittee->value,
            'quorum_general' => $this->quorumGeneral->value,
            'quorum_special_general' => $this->quorumSpecialGeneral->value,
            'resolution_required_for' => array_map(fn (ResolutionSubject $subject): string => $subject->value, $this->resolutionRequiredFor),
            'financing_society' => $this->financingSociety,
            'statutory_base' => $this->statutoryBase->value,
            'reserve' => $this->reserve->value,
            'development_fund' => $this->developmentFund->value,
            'bad_debt_fund' => $this->badDebtFund->value,
            'other_funds' => $this->otherFunds->value,
        ];
    }

    /**
     * Each setting in words, in the current language.
     *
     * @return array<string, string>
     */
    public function display(): array
    {
        $locale = app()->getLocale();
        $percent = fn (Bps $bps): string => $bps->format($locale);

        return [
            'expense_president_threshold' => Display::money($this->expensePresidentThreshold),
            'advance_refund_president_threshold' => Display::money($this->advanceRefundPresidentThreshold),
            'approval_reminder_days' => $this->approvalReminderDays === 0
                ? __('financial_settings.reminders_off')
                : __('financial_settings.days', ['count' => Display::digits((string) $this->approvalReminderDays)]),
            'quorum_committee' => $percent($this->quorumCommittee),
            'quorum_general' => $percent($this->quorumGeneral),
            'quorum_special_general' => $percent($this->quorumSpecialGeneral),
            'resolution_required_for' => $this->resolutionRequiredFor === []
                ? __('financial_settings.none')
                : implode(', ', array_map(fn (ResolutionSubject $subject): string => $subject->getLabel(), $this->resolutionRequiredFor)),
            'financing_society' => $this->financingSociety ? __('common.yes') : __('common.no'),
            'statutory_base' => $this->statutoryBase->getLabel(),
            'reserve' => $percent($this->reserve),
            'development_fund' => $percent($this->developmentFund),
            'bad_debt_fund' => $percent($this->badDebtFund),
            'other_funds' => $percent($this->otherFunds),
        ];
    }

    /**
     * The settings that differ from $old, in words: key => [old, new].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function changesFrom(self $old): array
    {
        $before = $old->display();
        $stored = $old->toStored();
        $changes = [];

        foreach ($this->toStored() as $key => $value) {
            if ($stored[$key] !== $value) {
                $changes[$key] = [$before[$key], $this->display()[$key]];
            }
        }

        return $changes;
    }

    public function changesYearEnd(self $old): bool
    {
        return array_intersect(array_keys($this->changesFrom($old)), self::YEAR_END_KEYS) !== [];
    }

    public function requiresResolution(ResolutionSubject $subject): bool
    {
        return in_array($subject, $this->resolutionRequiredFor, true);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<ResolutionSubject>
     */
    private static function subjects(array $values): array
    {
        $subjects = [];

        foreach (self::RESOLUTION_SUBJECTS as $subject) {
            if (in_array($subject->value, $values, true) || in_array($subject, $values, true)) {
                $subjects[] = $subject;
            }
        }

        return $subjects;
    }
}
