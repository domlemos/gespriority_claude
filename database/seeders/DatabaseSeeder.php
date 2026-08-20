<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Um usuário de teste por role (guard `web`), e-mail/senha previsíveis
     * para facilitar login manual/QA de cada nível de permissão.
     */
    private const ROLE_USERS = [
        'admin@example.com' => ['name' => 'Admin', 'role' => 'admin', 'grupo_solucao' => 'Administração'],
        'supervisor@example.com' => ['name' => 'Supervisor', 'role' => 'supervisor', 'grupo_solucao' => 'Suporte N2'],
        'agente@example.com' => ['name' => 'Agente', 'role' => 'agente', 'grupo_solucao' => 'Suporte N1'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(PoliticasSlaSeeder::class);
        $this->call(CategoriasSeeder::class);
        $this->call(GruposSolucaoSeeder::class);

        foreach (self::ROLE_USERS as $email => $data) {
            $grupoSolucaoId = GrupoSolucao::query()->where('nome', $data['grupo_solucao'])->value('id');

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $data['name'], 'password' => Hash::make('password'), 'grupo_solucao_id' => $grupoSolucaoId]
            );

            $user->roles()->sync([Role::query()->where('slug', $data['role'])->value('id')]);
        }

        $client = Client::query()->firstOrCreate(['name' => 'Empresa Teste']);

        Customer::query()->updateOrCreate(
            ['email' => 'cliente@example.com'],
            ['name' => 'Cliente Teste', 'client_id' => $client->id, 'password' => Hash::make('password')]
        );

        // Customers extras com dados aleatórios, só na primeira vez (senão
        // cresceria a cada `db:seed` — o de e-mail fixo acima já cobre o caso
        // de login previsível, isso aqui é só massa pra testar listagem/paginação).
        if (Customer::query()->count() <= 1) {
            Customer::factory()->count(3)->create(['client_id' => $client->id]);
        }

        $this->call(IncidentesSeeder::class);
    }
}
