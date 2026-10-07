<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\NomineeRelations\Pages;

use App\Domain\Members\Actions\SaveNomineeRelation;
use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Filament\Clusters\Settings\Resources\NomineeRelations\NomineeRelationResource;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

final class ManageNomineeRelations extends ManageRecords
{
    use ConfirmsWithTier;

    protected static string $resource = NomineeRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::tier1(
                CreateAction::make()
                    ->label(__('members.relation.add'))
                    ->tooltip(__('members.relation.add'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->using(fn (array $data): NomineeRelation => DomainActionRunner::run(
                        fn (User $actor): NomineeRelation => app(SaveNomineeRelation::class)($actor, null, NomineeRelationData::fromForm($data)),
                    )),
                __('members.relation.add'),
            ),
        ];
    }
}
