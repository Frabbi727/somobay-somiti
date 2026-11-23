<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Domain\Members\Portal\MemberPosition;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Member\Pages\Dues;
use App\Filament\Member\Pages\PayOnline;
use Filament\Widgets\Widget;

/**
 * The member home: what you owe, what you have paid, your advance, and a month strip — the
 * three answers a member needs first, in large type, with everything else one tap away.
 */
final class MemberPositionWidget extends Widget
{
    use ScopedToMember;

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.member.widgets.member-position';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'position' => app(MemberPosition::class)->for(self::member()),
            'duesUrl' => Dues::getUrl(),
            'payUrl' => PayOnline::getUrl(),
        ];
    }
}
