<?php

namespace Database\Factories;

use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidenteEvento>
 */
class IncidenteEventoFactory extends Factory
{
    protected $model = IncidenteEvento::class;

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
            'tipo' => IncidenteEvento::TIPO_RESOLVIDO,
        ];
    }
}
