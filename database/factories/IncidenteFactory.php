<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Incidente;
use App\Models\PoliticaSla;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incidente>
 */
class IncidenteFactory extends Factory
{
    protected $model = Incidente::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'item_id' => null,
            'grupo_solucao_id' => null,
            'responsavel_id' => null,
            'titulo' => fake()->sentence(4),
            'prioridade' => fake()->randomElement(PoliticaSla::PRIORIDADES),
            'origem' => fake()->randomElement(Incidente::ORIGENS),
            'status' => 'aberto',
        ];
    }
}
