<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidente_eventos', function (Blueprint $table) {
            // Chamado aberto pelo portal tem evento `aberto` sem usuário
            // (autor é o cliente); a FK continua.
            $table->foreignId('user_id')->nullable()->change();

            $table->foreignId('autor_customer_id')->nullable()->after('user_id')
                ->constrained('customers')->nullOnDelete();

            // true nos eventos reconstruídos (backfill): o grupo do autor é o
            // atual da pessoa, não o da época. Alimenta o aviso de "dados
            // aproximados" dos relatórios.
            $table->boolean('retroativo')->default(false);

            $table->index('autor_customer_id');
            $table->index(['tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('incidente_eventos', function (Blueprint $table) {
            $table->dropIndex(['tipo', 'created_at']);
            $table->dropIndex(['autor_customer_id']);
            $table->dropColumn('retroativo');
            $table->dropConstrainedForeignId('autor_customer_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
