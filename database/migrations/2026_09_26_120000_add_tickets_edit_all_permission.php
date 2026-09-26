<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Migration de dados (não só o seeder): produção não roda
    // RolesAndPermissionsSeeder a cada deploy, e sem a permission concedida
    // a Admin/Supervisor já na subida, os dois perderiam a edição completa
    // de chamados até alguém ajustar os papéis na mão.
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'Editar todos os campos do chamado',
            'slug' => 'tickets.edit_all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'tickets.edit_all')->value('id');

        DB::table('roles')
            ->whereIn('slug', ['admin', 'supervisor'])
            ->pluck('id')
            ->each(fn (int $roleId) => DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]));
    }

    public function down(): void
    {
        // permission_role cai junto via cascadeOnDelete.
        DB::table('permissions')->where('slug', 'tickets.edit_all')->delete();
    }
};
