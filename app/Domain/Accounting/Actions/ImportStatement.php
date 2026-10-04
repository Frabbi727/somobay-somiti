<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Accounting\Statements\ColumnMap;
use App\Domain\Accounting\Statements\ParsedLine;
use App\Domain\Accounting\Statements\StatementCsvParser;
use App\Domain\Accounting\Statements\StatementMatcher;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Imports a bank or wallet statement CSV (already stored on the local disk) and auto-matches it.
 * The same file twice is refused; rows already imported from an overlapping statement are skipped.
 */
final class ImportStatement
{
    public function __construct(
        private readonly StatementCsvParser $parser,
        private readonly StatementMatcher $matcher,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array<string, mixed>  $columns  user-chosen column names, overriding the detected ones
     */
    public function __invoke(User $actor, PaymentMethod $method, string $path, string $filename, array $columns = []): StatementImport
    {
        Gate::forUser($actor)->authorize('create', StatementImport::class);

        if ($method === PaymentMethod::Cash) {
            throw DomainRuleViolation::because('statements.errors.no_cash');
        }

        $contents = Storage::disk('local')->get($path) ?? throw DomainRuleViolation::because('statements.errors.empty');
        $hash = hash('sha256', $contents);

        if (StatementImport::query()->where('method', $method)->where('file_sha256', $hash)->exists()) {
            throw DomainRuleViolation::because('statements.errors.already_imported');
        }

        $headers = $this->parser->read($contents)['headers'];
        $lines = $this->parser->parse($contents, ColumnMap::detect($headers)->with($columns));

        return $this->causer->withCauser($actor, fn (): StatementImport => DB::transaction(function () use ($actor, $method, $path, $filename, $hash, $lines): StatementImport {
            $import = StatementImport::query()->create([
                'method' => $method,
                'filename' => $filename,
                'file_path' => $path,
                'file_sha256' => $hash,
                'imported_by' => $actor->id,
            ]);

            $inserted = 0;
            $seen = [];

            foreach ($lines as $line) {
                $key = implode('|', [$line->date->toDateString(), $line->amount->poisha, $line->reference, $line->description, $line->balance?->poisha]);
                $occurrence = $seen[$key] = ($seen[$key] ?? 0) + 1;
                $fingerprint = hash('sha256', $method->value.'|'.$key.'|'.$occurrence);

                $inserted += StatementLine::query()->insertOrIgnore([
                    'statement_import_id' => $import->id,
                    'method' => $method->value,
                    'line_no' => $line->lineNo,
                    'transacted_on' => $line->date->toDateString(),
                    'description' => $line->description,
                    'reference' => $line->reference === null ? null : mb_substr($line->reference, 0, 100),
                    'amount_poisha' => $line->amount->poisha,
                    'balance_poisha' => $line->balance?->poisha,
                    'fingerprint' => $fingerprint,
                    'status' => StatementLineStatus::Unmatched->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $last = $this->lastLine($lines);

            $import->update([
                'period_from' => min(array_map(fn (ParsedLine $line): string => $line->date->toDateString(), $lines)),
                'period_to' => max(array_map(fn (ParsedLine $line): string => $line->date->toDateString(), $lines)),
                'closing_balance_poisha' => $last->balance,
                'lines_count' => $inserted,
                'duplicates_skipped' => count($lines) - $inserted,
            ]);

            $this->matcher->autoMatch($import, $actor);

            activity('reconciliation')->performedOn($import)->withProperties(['lines' => $inserted])->log('imported');

            return $import;
        }, attempts: 3));
    }

    /**
     * The last row in date order (statements may be newest-first) — its balance is the closing balance.
     *
     * @param  non-empty-list<ParsedLine>  $lines
     */
    private function lastLine(array $lines): ParsedLine
    {
        $first = $lines[0];
        $final = $lines[count($lines) - 1];

        return $first->date->isAfter($final->date) ? $first : $final;
    }
}
