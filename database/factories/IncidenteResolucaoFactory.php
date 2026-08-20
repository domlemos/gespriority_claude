<?php

namespace Database\Factories;

use App\Models\Incidente;
use App\Models\IncidenteResolucao;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidenteResolucao>
 */
class IncidenteResolucaoFactory extends Factory
{
    protected $model = IncidenteResolucao::class;

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
        ];
    }
}
