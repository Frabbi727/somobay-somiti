<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = AccountType::Expense;

        return [
            'code' => $type->codePrefix().fake()->unique()->numerify('9##'),
            'name_en' => fake()->words(2, true),
            'name_bn' => 'পরীক্ষামূলক হিসাব',
            'type' => $type,
            'normal_balance' => $type->normalBalance(),
            'is_control' => false,
            'requires_member' => false,
            'is_active' => true,
        ];
    }

    public function ofType(AccountType $type): self
    {
        return $this->state(fn (): array => [
            'code' => $type->codePrefix().fake()->unique()->numerify('9##'),
            'type' => $type,
            'normal_balance' => $type->normalBalance(),
        ]);
    }
}
