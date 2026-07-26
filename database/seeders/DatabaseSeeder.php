<?php

namespace Database\Seeders;

use App\Models\Customer;
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
        'admin@example.com' => ['name' => 'Admin', 'role' => 'admin'],
        'supervisor@example.com' => ['name' => 'Supervisor', 'role' => 'supervisor'],
        'agente@example.com' => ['name' => 'Agente', 'role' => 'agente'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        foreach (self::ROLE_USERS as $email => $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $data['name'], 'password' => Hash::make('password')]
            );

            $user->roles()->sync([Role::query()->where('slug', $data['role'])->value('id')]);
        }

        Customer::query()->updateOrCreate(
            ['email' => 'cliente@example.com'],
            ['name' => 'Cliente Teste', 'password' => Hash::make('password')]
        );

        // Customers extras com dados aleatórios, só na primeira vez (senão
        // cresceria a cada `db:seed` — o de e-mail fixo acima já cobre o caso
        // de login previsível, isso aqui é só massa pra testar listagem/paginação).
        if (Customer::query()->count() <= 1) {
            Customer::factory()->count(3)->create();
        }
    }
}
