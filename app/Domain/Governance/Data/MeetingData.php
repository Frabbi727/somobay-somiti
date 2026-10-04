<?php

declare(strict_types=1);

namespace App\Domain\Governance\Data;

use App\Domain\Governance\Enums\MeetingType;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

final readonly class MeetingData
{
    public function __construct(
        public MeetingType $type,
        public string $title,
        public CarbonImmutable $scheduledAt,
        public ?string $venue = null,
        public ?string $agenda = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromForm(array $data): self
    {
        $text = function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $type = $data['type'] ?? null;

        return new self(
            type: $type instanceof MeetingType ? $type : MeetingType::from((string) $type),
            title: $text('title') ?? '',
            scheduledAt: CarbonImmutable::parse($text('scheduled_at') ?? 'now', YearMonth::TIMEZONE),
            venue: $text('venue'),
            agenda: $text('agenda'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'scheduled_at' => $this->scheduledAt,
            'venue' => $this->venue,
            'agenda' => $this->agenda,
        ];
    }
}
