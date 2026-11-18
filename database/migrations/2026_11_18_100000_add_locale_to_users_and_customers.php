<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de idioma da conta (pt_BR | en): define o idioma dos e-mails
 * e é sincronizada com a interface via /me.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'customers'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->string('locale', 5)->default('pt_BR');
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'customers'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('locale');
            });
        }
    }
};
