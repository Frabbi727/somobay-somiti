<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Policies\AuditEntryPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * One line of the audit log: who did what to which record, with the old and new values. Entries are
 * only ever added (a PostgreSQL trigger backs this up). Requests also record the IP address and browser.
 */
#[UsePolicy(AuditEntryPolicy::class)]
final class AuditEntry extends Activity
{
    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                $entry->properties = collect($entry->properties ?? [])->put('via', 'console');

                return;
            }

            $request = request();
            $entry->properties = collect($entry->properties ?? [])
                ->put('ip', $request->ip())
                ->put('agent', Str::limit((string) $request->userAgent(), 250, ''));
        });

        self::updating(fn (self $entry) => throw ImmutableRecord::for(self::class, $entry->getKey()));
        self::deleting(fn (self $entry) => throw ImmutableRecord::for(self::class, $entry->getKey()));
    }

    /**
     * A readable name for the kind of record, e.g. "Payment".
     */
    public function subjectLabel(): string
    {
        if ($this->subject_type === null) {
            return '—';
        }

        $base = Str::snake(class_basename($this->subject_type));
        $key = 'audit.subject.'.$base;

        return __($key) === $key ? Str::headline($base) : (string) __($key);
    }
}
