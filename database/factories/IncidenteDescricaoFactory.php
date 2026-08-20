<?php

namespace Database\Factories;

use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidenteDescricao>
 */
class IncidenteDescricaoFactory extends Factory
{
    protected $model = IncidenteDescricao::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incidente_id' => Incidente::factory(),
            'user_id' => User::factory(),
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => fake()->paragraph(),
        ];
    }
}
