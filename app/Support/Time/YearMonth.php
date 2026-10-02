<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A calendar month (e.g. July 2026), stored in the database as its first day: 2026-07-01.
 */
final readonly class YearMonth implements JsonSerializable, Stringable
{
    public const string TIMEZONE = 'Asia/Dhaka';

    private function __construct(public int $year, public int $month) {}

    public static function of(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(sprintf('Month must be between 1 and 12, [%d] given.', $month));
        }

        if ($year < 1900 || $year > 9999) {
            throw new InvalidArgumentException(sprintf('Year must be between 1900 and 9999, [%d] given.', $year));
        }

        return new self($year, $month);
    }

    /**
     * Parse "2026-07", or the stored first-day form "2026-07-01" (optionally with a midnight time).
     */
    public static function parse(string $value): self
    {
        if (preg_match('/^(\d{4})-(\d{2})(?:-01(?:[ T]00:00:00)?)?$/', trim($value), $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('Cannot parse [%s] as a year-month.', $value));
        }

        return self::of((int) $matches[1], (int) $matches[2]);
    }

    /**
     * The month containing the given moment, as seen in Asia/Dhaka.
     */
    public static function fromDate(DateTimeInterface $date): self
    {
        $local = CarbonImmutable::instance($date)->setTimezone(self::TIMEZONE);

        return self::of($local->year, $local->month);
    }

    public static function current(): self
    {
        return self::fromDate(CarbonImmutable::now(self::TIMEZONE));
    }

    /**
     * Every month from $from to $to inclusive; empty when $to is before $from.
     *
     * @return list<self>
     */
    public static function range(self $from, self $to): array
    {
        $months = [];

        for ($month = $from; $month->isSameOrBefore($to); $month = $month->next()) {
            $months[] = $month;
        }

        return $months;
    }

    public function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1, 0, 0, 0, self::TIMEZONE)
            ?? throw new InvalidArgumentException('Invalid year-month.');
    }

    public function lastDay(): CarbonImmutable
    {
        return $this->firstDay()->endOfMonth()->startOfDay();
    }

    public function daysInMonth(): int
    {
        return $this->firstDay()->daysInMonth;
    }

    /**
     * A specific day of this month, e.g. the due date for due_day 10.
     */
    public function day(int $day): CarbonImmutable
    {
        if ($day < 1 || $day > $this->daysInMonth()) {
            throw new InvalidArgumentException(sprintf('Day [%d] does not exist in %s.', $day, $this));
        }

        return $this->firstDay()->setDay($day);
    }

    public function addMonths(int $months): self
    {
        $index = $this->index() + $months;

        return self::of(intdiv($index, 12), $index % 12 + 1);
    }

    public function subMonths(int $months): self
    {
        return $this->addMonths(-$months);
    }

    public function next(): self
    {
        return $this->addMonths(1);
    }

    public function previous(): self
    {
        return $this->addMonths(-1);
    }

    /**
     * Number of months from this month to $other (negative when $other is earlier).
     */
    public function monthsUntil(self $other): int
    {
        return $other->index() - $this->index();
    }

    /**
     * @return -1|0|1
     */
    public function compare(self $other): int
    {
        return $this->index() <=> $other->index();
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isBefore(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isSameOrBefore(self $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isSameOrAfter(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    /**
     * The database form: the first day of the month, e.g. "2026-07-01".
     */
    public function toDateString(): string
    {
        return sprintf('%04d-%02d-01', $this->year, $this->month);
    }

    public function __toString(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    private function index(): int
    {
        return $this->year * 12 + $this->month - 1;
    }
}
