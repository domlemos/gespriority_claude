<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Subcategoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subcategoria>
 */
class SubcategoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'categoria_id' => Categoria::factory(),
            'nome' => fake()->unique()->word(),
            'ativo' => true,
        ];
    }
}
