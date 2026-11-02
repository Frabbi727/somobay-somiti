<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Members\Terms\Actions\AcceptTerms;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Support\DomainActionRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Where a member who has not yet accepted the current rules is sent: read them, tick, agree.
 *
 * @property-read Schema $form
 */
final class AcceptRules extends Page
{
    use ConfirmsWithTier;
    use ScopedToMember;

    protected string $view = 'filament.member.accept-rules';

    protected static ?string $slug = 'accept-rules';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public array $data = [];

    /** One key per page load, so a double click stores the acceptance once. */
    #[Locked]
    public string $idempotencyKey = '';

    /** The version shown when the page opened: that is what the member agrees to. */
    #[Locked]
    public int $version = 0;

    public function getTitle(): string
    {
        return __('terms.portal.title');
    }

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();
        $this->version = (int) MembershipTerms::current()->version;
        $this->form->fill(['agree' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Checkbox::make('agree')
                ->label(__('terms.portal.agree'))
                ->accepted()
                ->validationMessages(['accepted' => __('terms.portal.agree_required')]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $bn = app()->getLocale() === 'bn';
        $terms = MembershipTerms::query()->where('version', $this->version)->firstOrFail();

        return [
            'shownVersion' => $this->version,
            'rules' => $bn ? $terms->rules_bn : $terms->rules_en,
            'declaration' => $bn ? $terms->declaration_bn : $terms->declaration_en,
        ];
    }

    public function acceptAction(): Action
    {
        return self::tier1(
            Action::make('accept')
                ->label(__('terms.portal.accept'))
                ->tooltip(__('terms.portal.accept'))
                ->mountUsing(fn () => $this->form->validate())
                ->action(function (): void {
                    $member = self::member();

                    DomainActionRunner::run(fn ($actor) => app(AcceptTerms::class)($actor, $member, new TermsConsent(
                        $this->version,
                        ConsentChannel::Portal,
                        $this->idempotencyKey,
                        request()->ip(),
                    )));

                    Notification::make()->title(__('terms.portal.accepted'))->success()->send();

                    $this->redirect(Dashboard::getUrl());
                }),
            heading: __('terms.portal.accept'),
        );
    }
}
