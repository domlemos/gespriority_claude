<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidentes', function (Blueprint $table) {
            // Chamado aberto pelo portal nasce sem classificação — o staff
            // classifica na triagem, e só então a prioridade é derivada do
            // item (ver BACKEND_SPECS.md §3.1).
            $table->string('prioridade')->nullable()->change();

            // Cliente que abriu pelo portal (contraparte de criado_por_id,
            // que é sempre staff). Os dois nunca são preenchidos juntos.
            $table->foreignId('criado_por_customer_id')->nullable()->after('criado_por_id')
                ->constrained('customers')->restrictOnDelete();
        });

        Schema::table('incidente_descricoes', function (Blueprint $table) {
            // Autor passa a ser um User (staff) OU um Customer (descrição de
            // abertura feita pelo portal) — exatamente um dos dois.
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained('customers')->restrictOnDelete();
        });

        // SQLite (testes) não aceita ADD CONSTRAINT — lá a regra fica só na
        // guarda do model (IncidenteDescricao::booted()).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE incidente_descricoes ADD CONSTRAINT incidente_descricoes_um_autor CHECK ((user_id IS NULL) <> (customer_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE incidente_descricoes DROP CONSTRAINT incidente_descricoes_um_autor');
        }

        Schema::table('incidente_descricoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('incidentes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('criado_por_customer_id');
            $table->string('prioridade')->nullable(false)->change();
        });
    }
};
