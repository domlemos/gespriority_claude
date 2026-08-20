<?php

namespace Database\Factories;

use App\Models\PoliticaSla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PoliticaSla>
 */
class PoliticaSlaFactory extends Factory
{
    protected $model = PoliticaSla::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->words(3, true),
            'prioridade' => fake()->randomElement(PoliticaSla::PRIORIDADES),
            'tempo_resposta_minutos' => 60,
            'tempo_resolucao_minutos' => 480,
            'apenas_horas_uteis' => true,
            'ativo' => true,
            'client_id' => null,
        ];
    }
}
