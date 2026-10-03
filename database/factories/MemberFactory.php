<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * A bare member row without shares or dues, for tests that only need a member to exist.
 * Use CreateMember for the full onboarding flow.
 *
 * @extends Factory<Member>
 */
final class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_no' => fn (): string => sprintf('M-%04d', (int) DB::scalar("SELECT nextval('member_no_seq')")),
            'name_bn' => 'সদস্য',
            'name_en' => fake()->name(),
            'mobile' => '01'.fake()->randomElement(['3', '5', '6', '7', '8', '9']).fake()->unique()->numerify('########'),
            'status' => MemberStatus::Active,
            'joined_on' => '2026-07-01',
            'created_by' => User::factory(),
        ];
    }
}
