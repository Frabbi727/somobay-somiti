<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Actions;

use App\Domain\GoLive\Data\OpeningImportError;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\GoLive\Services\OpeningBalanceSheet;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Domain\GoLive\Services\OpeningWorkbookReader;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Reads and checks the go-live workbook and keeps it as the one draft (a new upload replaces it).
 * A draft changes no money; posting is a separate, confirmed step.
 */
final class UploadOpeningImport
{
    public function __construct(
        private readonly OpeningWorkbookReader $reader,
        private readonly OpeningImportValidator $validator,
        private readonly OpeningBalanceSheet $sheet,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  string  $path  relative to the local disk
     */
    public function __invoke(User $actor, string $path, string $fileName, YearMonth $goLive): OpeningImport
    {
        Gate::forUser($actor)->authorize('create', OpeningImport::class);

        if (OpeningImport::query()->where('status', OpeningImportStatus::Posted)->exists()) {
            throw DomainRuleViolation::because('golive.errors.already_posted');
        }

        $disk = Storage::disk('local');
        $book = $this->reader->read($disk->path($path));
        $validated = $this->validator->validate($book, $goLive);
        $hash = hash('sha256', (string) $disk->get($path));

        return $this->causer->withCauser($actor, fn (): OpeningImport => DB::transaction(function () use ($actor, $path, $fileName, $goLive, $book, $validated, $hash): OpeningImport {
            if (OpeningImport::query()->where('status', OpeningImportStatus::Posted)->lockForUpdate()->exists()) {
                throw DomainRuleViolation::because('golive.errors.already_posted');
            }

            $draft = OpeningImport::query()->where('status', OpeningImportStatus::Draft)->lockForUpdate()->first() ?? new OpeningImport(['status' => OpeningImportStatus::Draft]);
            $oldPath = $draft->exists ? $draft->file_path : null;

            $draft->fill([
                'go_live_month' => $goLive,
                'file_path' => $path,
                'file_name' => $fileName,
                'file_sha256' => $hash,
                'payload' => $book->toArray(),
                'errors' => array_map(fn (OpeningImportError $error): array => $error->toArray(), $validated->errors),
                'summary' => $this->sheet->summary($validated),
                'uploaded_by' => $actor->id,
            ])->save();

            if ($oldPath !== null && $oldPath !== $path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
            }

            activity('golive')
                ->performedOn($draft)
                ->event('opening_import_uploaded')
                ->withProperties(['file' => $fileName, 'go_live' => (string) $goLive, 'errors' => count($validated->errors), 'members' => count($validated->members)])
                ->log('opening import uploaded');

            return $draft;
        }, attempts: 3));
    }
}
