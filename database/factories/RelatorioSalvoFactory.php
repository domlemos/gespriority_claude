<?php

namespace Database\Factories;

use App\Models\RelatorioSalvo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatorioSalvo>
 */
class RelatorioSalvoFactory extends Factory
{
    protected $model = RelatorioSalvo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nome' => fake()->sentence(3),
            'filtros' => [],
            'agrupar_por' => fake()->randomElement(RelatorioSalvo::AGRUPAMENTOS),
        ];
    }
}
