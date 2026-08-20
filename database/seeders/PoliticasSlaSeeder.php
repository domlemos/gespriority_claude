<?php

namespace Database\Seeders;

use App\Models\PoliticaSla;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PoliticasSlaSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Políticas "padrão global" (client_id nulo), uma por prioridade — é o
     * que `Client::resolvedSlaFor()` usa quando o cliente não tem uma
     * política própria (ver seção 3.1 do BACKEND_SPECS.md).
     */
    private const POLITICAS_PADRAO = [
        'urgente' => ['nome' => 'Padrão Urgente', 'tempo_resposta_minutos' => 15, 'tempo_resolucao_minutos' => 240],
        'alta' => ['nome' => 'Padrão Alta', 'tempo_resposta_minutos' => 60, 'tempo_resolucao_minutos' => 480],
        'media' => ['nome' => 'Padrão Média', 'tempo_resposta_minutos' => 240, 'tempo_resolucao_minutos' => 1440],
        'baixa' => ['nome' => 'Padrão Baixa', 'tempo_resposta_minutos' => 480, 'tempo_resolucao_minutos' => 2880],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::POLITICAS_PADRAO as $prioridade => $data) {
            PoliticaSla::query()->updateOrCreate(
                ['client_id' => null, 'prioridade' => $prioridade],
                [...$data, 'apenas_horas_uteis' => true, 'ativo' => true]
            );
        }
    }
}
