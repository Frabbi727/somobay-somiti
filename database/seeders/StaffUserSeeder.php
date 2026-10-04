<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One staff login per role, so every part of the panel can be tried (password: "password").
 * Members get their logins from the member portal, not here.
 *
 * In production only the super admin is created — change its password right after the first login.
 */
final class StaffUserSeeder extends Seeder
{
    public const string PASSWORD = 'password';

    /**
     * email => [name, role]
     *
     * @var array<string, array{0: string, 1: Role}>
     */
    public const array USERS = [
        'admin@somiti.test' => ['Super Admin', Role::SuperAdmin],
        'president@somiti.test' => ['Abdul Karim (President)', Role::President],
        'secretary@somiti.test' => ['Shirin Akter (Secretary)', Role::Secretary],
        'cashier@somiti.test' => ['Mizanur Rahman (Cashier)', Role::Cashier],
        'accountant@somiti.test' => ['Tanvir Hasan (Accountant)', Role::Accountant],
        'accountant2@somiti.test' => ['Rokeya Sultana (Accountant 2)', Role::Accountant],
        'auditor@somiti.test' => ['Mahbub Alam (Auditor)', Role::Auditor],
    ];

    public function run(): void
    {
        $this->call(RoleSeeder::class);

        foreach (self::USERS as $email => [$name, $role]) {
            if (app()->isProduction() && $role !== Role::SuperAdmin) {
                continue;
            }

            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD, 'locale' => 'bn']);
            $user->syncRoles([$role->value]);
        }
    }
}
