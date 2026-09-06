<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        // Índice único parcial: e-mail só precisa ser único entre usuários
        // ativos. Um usuário soft-deleted não bloqueia mais o e-mail para um
        // cadastro novo e independente (Postgres e SQLite suportam esse
        // índice condicional com a mesma sintaxe).
        DB::statement('create unique index users_email_unique on users (email) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index users_email_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
