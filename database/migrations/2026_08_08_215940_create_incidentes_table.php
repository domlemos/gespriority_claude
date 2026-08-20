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
        Schema::create('incidentes', function (Blueprint $table) {
            $table->id();

            // Quem abriu/é o afetado — sempre um Customer (guard "customer"),
            // nunca um User (ver BACKEND_SPECS.md seção 1.3). Obrigatório e
            // restrict: registro histórico, não pode sumir com o cliente.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // Classificação (Categoria/Subcategoria deriváveis via item->
            // subcategoria->categoria) e roteamento — nullable: podem ser
            // definidos na triagem, não necessariamente na abertura.
            $table->foreignId('item_id')->nullable()->constrained('itens')->restrictOnDelete();
            $table->foreignId('grupo_solucao_id')->nullable()->constrained('grupos_solucao')->restrictOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('titulo');
            $table->text('descricao');

            // prioridade reaproveita os mesmos valores de PoliticaSla::PRIORIDADES
            // (baixa|media|alta|urgente) — sem duplicar a lista de constantes.
            $table->string('prioridade');

            // origem e status são constantes do próprio Incidente (ver
            // Incidente::ORIGENS / Incidente::STATUSES) — não geram cadastro
            // próprio, mesma decisão de design de `prioridade`.
            $table->string('origem');
            $table->string('status')->default('aberto');

            $table->timestamps();

            $table->index('status');
            $table->index('grupo_solucao_id');
            $table->index('responsavel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidentes');
    }
};
