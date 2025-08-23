<?php

namespace Database\Factories;

use App\Models\Industry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Industry>
 */
class IndustryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => null,
            'depth' => 0,
            'path' => null, // Will be set by observer
            'sort' => fake()->numberBetween(1, 100),
            'is_active' => true,
            'visible_in_ui' => true,
        ];
    }

    /**
     * Create a root industry state.
     */
    public function root(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => null,
            'depth' => 0,
        ]);
    }

    /**
     * Create a child industry state.
     */
    public function child(Industry $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
            'depth' => $parent->depth + 1,
        ]);
    }

    /**
     * Create an inactive industry state.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a hidden industry state.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'visible_in_ui' => false,
        ]);
    }
}
