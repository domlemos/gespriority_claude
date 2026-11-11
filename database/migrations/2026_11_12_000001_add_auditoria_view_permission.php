<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Migration de dados (mesmo motivo de add_tickets_edit_all_permission):
    // produção não roda RolesAndPermissionsSeeder a cada deploy. Só Admin.
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'Visualizar logs de ações',
            'slug' => 'auditoria.view',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'auditoria.view')->value('id');

        DB::table('roles')
            ->where('slug', 'admin')
            ->pluck('id')
            ->each(fn (int $roleId) => DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]));
    }

    public function down(): void
    {
        DB::table('permissions')->where('slug', 'auditoria.view')->delete();
    }
};
