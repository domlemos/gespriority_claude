<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Subcategoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subcategoria_id' => Subcategoria::factory(),
            'nome' => fake()->unique()->words(2, true),
            'ativo' => true,
        ];
    }
}
