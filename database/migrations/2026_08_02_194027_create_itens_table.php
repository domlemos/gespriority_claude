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
        Schema::create('itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcategoria_id')->constrained()->restrictOnDelete();
            $table->string('nome');
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            // subcategoria_id nunca é nulo, então unique composta de verdade
            // funciona sem a pegadinha de NULL (mesmo raciocínio de subcategorias).
            $table->unique(['subcategoria_id', 'nome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itens');
    }
};
