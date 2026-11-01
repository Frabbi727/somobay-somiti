<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Geo\Data\PostalAddress;
use App\Domain\Geo\Services\GeoDirectory;

/**
 * A saved address as one line of text: street line, then union, upazila, district, division.
 */
final class AddressDisplay
{
    public static function of(object $row, string $prefix): ?string
    {
        $address = PostalAddress::fromAttributes($row, $prefix);

        if ($address === null) {
            return null;
        }

        $place = $address->upazilaId > 0 ? app(GeoDirectory::class)->describe($address->upazilaId, $address->unionId) : '';

        return trim(implode(', ', array_filter([$address->line, $place], fn (string $part): bool => $part !== '')));
    }
}
