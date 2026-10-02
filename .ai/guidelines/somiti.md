# Somiti Manager — Project Rules

The full specification is `SOMITI_SPEC.md` in the repository root. Build one step from §10 at a time; after each step `composer test`, `composer analyse` and `composer format:check` must pass.

## Coding standards (SOMITI_SPEC.md §3)

1. **No business logic in UI.** Filament and Livewire only gather input, call one Action and show the result.
2. **Actions** are final invokable classes in `app/Domain/{Module}/Actions`. Every financial Action runs in `DB::transaction(..., attempts: 3)`, locks the rows it mutates with `lockForUpdate()` (lock order: member → dues → advance ledger → voucher sequence), checks invariants before commit, writes the activity log, and throws `DomainRuleViolation` with bn/en translation keys.
3. **DTOs** are `final readonly class`; money fields are `Money`, never int/float/string.
4. **Enums** are backed enums for every status/type, implementing Filament `HasLabel`, `HasColor`, `HasIcon`.
5. **Money:** no `float`, no `round()`/`floor()`/`ceil()`/`number_format()`, no `/` on money. Store integer poisha in BIGINT `_poisha` columns with `MoneyCast`. Percentages are INT basis points (`_bps`, 1% = 100). JavaScript never computes money. Rounding happens only at the named points in §6.2; every split uses `Allocator::largestRemainder()`.
6. **Immutability:** posted journals, approved rate plans, approved payments and due amounts never change. Model guards throw; PostgreSQL triggers back them up. Corrections are reversals or new versions.
7. **Policies** on every model; never check roles inline in UI. Financial models' `delete`/`forceDelete` return false.
8. **Idempotency:** natural unique keys for every generator; an `idempotency_key` UUID for every externally triggered write.
9. **Time:** `CarbonImmutable`; the `YearMonth` value object is stored as the 1st of the month; business dates use Asia/Dhaka. Fiscal year is 1 July – 30 June.
10. **Tests (Pest):** every Action covers the happy path, each rule violation and concurrency where relevant; money algorithms get invariant tests. Arch rules live in `tests/Arch`.
11. Larastan level 8 with no baseline; Pint (with `declare_strict_types`) on every change.
12. English code; Bangla only in `lang/bn`; every user-facing string goes through `__()` and exists in both `lang/bn` and `lang/en`.
13. Forward-only migrations once in production.
14. No hard deletes of anything with financial impact (§7.4).

## Confirmation tiers (§7)

Every Filament write action uses the shared `ConfirmsWithTier` trait: T1 simple confirm for non-financial edits, T2 diff/summary for member/settings/share/payment entry, T3 typed confirmation for approve/reject/reverse/post/year-end/exit/bulk/deactivate. Financial actions always use `->action()`, never `->url()`.

## Database

PostgreSQL. Money columns are `bigInteger`/`unsignedBigInteger`. Use PostgreSQL features where the spec calls for them: deferred constraint trigger for balanced journals, partial unique indexes, CHECK constraints.
