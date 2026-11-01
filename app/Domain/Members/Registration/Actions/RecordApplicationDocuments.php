<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Enums\RegistrationDocument;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The office notes which paper documents arrived with an application that is still open.
 */
final class RecordApplicationDocuments
{
    public function __construct(private readonly CauserResolver $causer) {}

    /**
     * @param  list<RegistrationDocument>  $documents
     */
    public function __invoke(User $actor, MemberApplication $application, array $documents): MemberApplication
    {
        Gate::forUser($actor)->authorize('create', MemberApplication::class);

        return $this->causer->withCauser($actor, fn (): MemberApplication => DB::transaction(function () use ($application, $documents): MemberApplication {
            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw DomainRuleViolation::because('registration.errors.not_open');
            }

            $unique = [];

            foreach ($documents as $document) {
                if (! in_array($document, $unique, true)) {
                    $unique[] = $document;
                }
            }

            $locked->documents_received = collect($unique);
            $locked->save();

            activity('members')
                ->performedOn($locked)
                ->event('registration_documents_recorded')
                ->withProperties(['documents' => array_map(fn (RegistrationDocument $document): string => $document->value, $unique)])
                ->log('registration documents recorded');

            return $locked;
        }, attempts: 3));
    }
}
