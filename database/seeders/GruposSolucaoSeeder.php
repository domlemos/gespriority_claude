<?php

namespace Database\Seeders;

use App\Models\GrupoSolucao;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GruposSolucaoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Grupos padrão — precisa existir pelo menos um antes de qualquer User
     * ser criado, já que `grupo_solucao_id` é obrigatório na tabela `users`
     * (todo User pertence a um grupo; só Customer é isento — ver
     * BACKEND_SPECS.md seção 3.1).
     */
    private const GRUPOS = ['Suporte N1', 'Suporte N2', 'Redes', 'Administração'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::GRUPOS as $nome) {
            GrupoSolucao::query()->updateOrCreate(['nome' => $nome], ['ativo' => true]);
        }
    }
}
