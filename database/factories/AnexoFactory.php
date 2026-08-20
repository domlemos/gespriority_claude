<?php

namespace Database\Factories;

use App\Models\Anexo;
use App\Models\Incidente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Anexo>
 */
class AnexoFactory extends Factory
{
    protected $model = Anexo::class;

    public function configure(): static
    {
        // 'caminho' não é fillable (ver Anexo::class), então precisa ser
        // setado por atribuição direta, não pelo array de definition().
        return $this->afterMaking(function (Anexo $anexo) {
            $anexo->caminho = 'anexos/incidentes/fake/'.fake()->uuid().'.pdf';
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 'caminho' fica de fora de propósito — não é fillable (ver
        // App\Models\Anexo), então um valor aqui seria descartado
        // silenciosamente pelo create(). Testes que precisam de um caminho
        // real usam forceFill()->save(), igual ao padrão de
        // prazo_resposta/prazo_resolucao em IncidenteFactory.
        return [
            'incidente_id' => Incidente::factory(),
            'user_id' => User::factory(),
            'nome_original' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'tamanho' => fake()->numberBetween(1024, 1024 * 1024),
        ];
    }
}
