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
        Schema::create('politicas_sla', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            // baixa|media|alta|urgente — validado na aplicação (ver
            // PoliticaSla::PRIORIDADES), sem enum/check nativo do banco para
            // manter portabilidade entre Postgres (prod) e SQLite (testes).
            $table->string('prioridade');
            $table->unsignedInteger('tempo_resposta_minutos');
            $table->unsignedInteger('tempo_resolucao_minutos');
            $table->boolean('apenas_horas_uteis')->default(false);
            $table->boolean('ativo')->default(true);
            // Nullable: uma política sem client_id é um "padrão global" para
            // aquela prioridade (ver PoliticaSlaController e nota na seção
            // 3.1 do BACKEND_SPECS.md sobre resolução implícita do padrão).
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Não é unique: (client_id, prioridade) com client_id NULL não
            // colide de forma confiável entre Postgres/SQLite (NULL não é
            // igual a NULL para fins de unique constraint). A unicidade é
            // garantida na validação do controller (Rule::unique com
            // whereNull condicional), não no banco.
            $table->index(['client_id', 'prioridade']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('politicas_sla');
    }
};
