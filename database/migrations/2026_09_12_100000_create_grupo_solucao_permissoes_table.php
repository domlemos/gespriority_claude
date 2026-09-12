<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupo_solucao_permissoes', function (Blueprint $table) {
            $table->foreignId('grupo_solucao_id')->constrained('grupos_solucao')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            // 'liberada' (o grupo concede essa permission além do que o Role
            // já dá) ou 'bloqueada' (o grupo retira essa permission mesmo
            // que o Role conceda) — ver User::hasPermission().
            $table->string('tipo');
            $table->primary(['grupo_solucao_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_solucao_permissoes');
    }
};
