<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subcategorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained()->restrictOnDelete();
            $table->string('nome');
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            // categoria_id nunca é nulo aqui (diferente de politicas_sla.client_id),
            // então uma unique composta de verdade funciona sem a pegadinha de NULL
            // não colidir consigo mesmo entre Postgres/SQLite.
            $table->unique(['categoria_id', 'nome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcategorias');
    }
};
