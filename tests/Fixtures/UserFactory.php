<?php

namespace Huwiya\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'huwiya_id' => (string) Str::ulid(),
            'name' => fake()->name(),
            'phone' => '+964'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
