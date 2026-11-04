<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Data;

/**
 * The workbook after checking: typed rows (rows with an error left out) and every error found.
 */
final readonly class ValidatedOpening
{
    /**
     * @param  list<OpeningMemberRow>  $members
     * @param  list<OpeningSocietyRow>  $society
     * @param  list<OpeningInvestmentRow>  $investments
     * @param  list<OpeningImportError>  $errors
     */
    public function __construct(
        public array $members,
        public array $society,
        public array $investments,
        public array $errors,
    ) {}

    public function isClean(): bool
    {
        return $this->errors === [];
    }
}
