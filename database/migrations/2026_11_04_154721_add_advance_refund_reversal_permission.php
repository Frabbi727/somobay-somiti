<?php

declare(strict_types=1);

use App\Domain\Settings\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * An advance refund made by mistake is reversed by a mirror voucher and a reversal row in the
     * advance ledger (linked through its unique reverses_entry_id). Only the new permission is
     * installed here, for the accountant and the president.
     */
    public function up(): void
    {
        app(RolePermissions::class)->installDefaults();
    }
};
