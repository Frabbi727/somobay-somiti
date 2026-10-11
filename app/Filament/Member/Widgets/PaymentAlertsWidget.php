<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Member\Pages\Payments;
use App\Filament\Member\Pages\PayOnline;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Tells the member, before anything else, about payments still being checked by the office and
 * payments turned down or cancelled recently (with the reason), so they know whether to pay again.
 */
final class PaymentAlertsWidget extends Widget
{
    use ScopedToMember;

    /** Rejected or cancelled payments from the last this many days are shown. */
    public const int RECENT_DAYS = 30;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected string $view = 'filament.member.widgets.payment-alerts';

    public static function canView(): bool
    {
        return self::canAccess() && (self::pending()->isNotEmpty() || self::turnedDown()->isNotEmpty());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'pending' => self::pending(),
            'turnedDown' => self::turnedDown(),
            'paymentsUrl' => Payments::getUrl(),
            'payUrl' => PayOnline::getUrl(),
        ];
    }

    /**
     * @return Collection<int, Payment>
     */
    private static function pending(): Collection
    {
        return Payment::query()->where('member_id', self::member()->id)->where('status', PaymentStatus::Pending)->latest('id')->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    private static function turnedDown(): Collection
    {
        return Payment::query()
            ->where('member_id', self::member()->id)
            ->whereIn('status', [PaymentStatus::Rejected, PaymentStatus::Reversed])
            ->where('updated_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->latest('updated_at')
            ->limit(3)
            ->get();
    }
}
