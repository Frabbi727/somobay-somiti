# Somobay Somiti

Bookkeeping and member management for a Bangladeshi cooperative society (সমবায় সমিতি): members and shares, monthly dues, payments with maker-checker approval, expenses, investments, year-end profit distribution, a member portal and a mobile app API. Every amount is integer poisha in a double-entry ledger that checks itself every night.

Built with Laravel 13, Filament 5 and PostgreSQL. The full specification is `SOMITI_SPEC.md`; coding rules are in `CLAUDE.md` / `AGENTS.md`.

## Documentation

| For | Read |
|---|---|
| Everyone | [Business overview](docs/business-overview.md) · [Glossary](docs/business-glossary.md) |
| Staff and members | [User manual — বাংলা](docs/user-manual-bn.md) · [User manual — English](docs/user-manual-en.md) · [Error resolution guide](docs/error-resolution-guide.md) |
| Committee | [Roles and permissions](docs/roles-and-permissions.md) · [Approval workflows](docs/approval-workflows.md) · [Notification matrix](docs/notification-matrix.md) · [Dashboard by role](docs/dashboard-by-role.md) · [Known gaps and risks](docs/known-gaps-and-risks.md) |
| Accounts and audit | [Financial calculation rules](docs/financial-calculation-rules.md) |
| Developers | [Page guide](docs/module-and-page-guide.md) · [Testing and acceptance](docs/testing-and-acceptance-criteria.md) · [Local testing](docs/LOCAL_TESTING.md) |

## Running it locally

See [docs/LOCAL_TESTING.md](docs/LOCAL_TESTING.md). In short:

```bash
composer install && npm install && npm run build
php artisan somiti:demo --fresh   # wipes the local database and builds a demo somiti
php artisan serve --port=8002
php artisan queue:work            # sends SMS and emails
php artisan schedule:work         # dues, late fees, reminders, self-check, backups
```

## Checks

```bash
composer test && composer analyse && composer format:check
```
