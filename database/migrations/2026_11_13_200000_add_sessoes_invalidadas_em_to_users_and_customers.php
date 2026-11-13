<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invalidação global das sessões por cookie (SPA): sessão iniciada antes
 * deste instante deixa de valer — "sair de todos os dispositivos" e
 * redefinição de senha. Ver App\Support\Auth\GuardSessaoOuToken.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'customers'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->timestamp('sessoes_invalidadas_em')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'customers'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('sessoes_invalidadas_em');
            });
        }
    }
};
