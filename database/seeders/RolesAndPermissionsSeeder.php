<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = collect([
            ['name' => 'Gerenciar usuários', 'slug' => 'users.manage'],
            ['name' => 'Gerenciar papéis e permissões', 'slug' => 'roles.manage'],
            ['name' => 'Gerenciar clientes', 'slug' => 'clients.manage'],
            ['name' => 'Visualizar chamados', 'slug' => 'tickets.view'],
            ['name' => 'Atribuir chamados', 'slug' => 'tickets.assign'],
        ])->mapWithKeys(fn (array $data) => [
            $data['slug'] => Permission::query()->updateOrCreate(['slug' => $data['slug']], $data),
        ]);

        // "Cliente" não é uma role do RBAC: quem abre chamado é sempre o model
        // Customer (guard próprio, sem roles/permissions — ver seção 1.3 do
        // BACKEND_SPECS.md). Uma role "cliente" aqui não representaria nenhum
        // ator real, já que nenhum User deveria assumi-la.
        $roles = collect([
            'admin' => ['name' => 'Admin', 'slug' => 'admin'],
            'supervisor' => ['name' => 'Supervisor', 'slug' => 'supervisor'],
            'agente' => ['name' => 'Agente', 'slug' => 'agente'],
        ])->map(fn (array $data) => Role::query()->updateOrCreate(['slug' => $data['slug']], $data));

        $roles['admin']->permissions()->sync($permissions->pluck('id'));

        $roles['supervisor']->permissions()->sync(
            $permissions->only(['tickets.view', 'tickets.assign'])->pluck('id')
        );

        $roles['agente']->permissions()->sync(
            $permissions->only(['tickets.view'])->pluck('id')
        );
    }
}
