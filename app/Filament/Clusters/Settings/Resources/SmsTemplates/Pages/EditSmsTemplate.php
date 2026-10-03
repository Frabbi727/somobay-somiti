<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\SmsTemplates\Pages;

use App\Domain\Notifications\Actions\UpdateSmsTemplate;
use App\Domain\Notifications\Models\SmsTemplate;
use App\Filament\Clusters\Settings\Resources\SmsTemplates\SmsTemplateResource;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property SmsTemplate $record
 */
final class EditSmsTemplate extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = SmsTemplateResource::class;

    protected function getSaveFormAction(): Action
    {
        $labels = [
            'body_bn' => __('sms.template.body_bn'),
            'body_en' => __('sms.template.body_en'),
            'is_active' => __('sms.template.is_active'),
        ];

        return self::tier2(
            parent::getSaveFormAction()->submit(null),
            heading: fn (): string => __('sms.template.save_heading', ['key' => $this->record->key->getLabel()]),
            rows: fn (): array => ChangeSummary::rows(
                $labels,
                ['body_bn' => $this->record->body_bn, 'body_en' => $this->record->body_en, 'is_active' => $this->record->is_active],
                ['body_bn' => $this->data['body_bn'] ?? null, 'body_en' => $this->data['body_en'] ?? null, 'is_active' => (bool) ($this->data['is_active'] ?? false)],
            ),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->save());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActionRunner::run(fn (User $actor): SmsTemplate => app(UpdateSmsTemplate::class)(
            $actor,
            $this->record,
            (string) ($data['body_bn'] ?? ''),
            (string) ($data['body_en'] ?? ''),
            (bool) ($data['is_active'] ?? false),
        ));
    }
}
