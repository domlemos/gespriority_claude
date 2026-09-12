<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_grupo_solucao_visibilidade', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grupo_solucao_id')->constrained('grupos_solucao')->cascadeOnDelete();
            $table->primary(['user_id', 'grupo_solucao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_grupo_solucao_visibilidade');
    }
};
