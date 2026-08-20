<?php

namespace Database\Factories;

use App\Models\GrupoSolucao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrupoSolucao>
 */
class GrupoSolucaoFactory extends Factory
{
    protected $model = GrupoSolucao::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->words(2, true),
            'ativo' => true,
        ];
    }
}
