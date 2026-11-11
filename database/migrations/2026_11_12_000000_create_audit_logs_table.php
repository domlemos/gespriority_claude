<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log de ações de usuários (App\Models\AuditLog) — substitui
 * `token_audit_logs`, que só cobria emissão/revogação de token: os registros
 * antigos são copiados pra cá (ação traduzida, nome do autor resolvido) e a
 * tabela antiga é removida. Ver BACKEND_SPECS.md §3.4.11.
 */
return new class extends Migration
{
    private const ACOES_TOKEN = [
        'issued' => 'login',
        'refreshed' => 'token_renovado',
        'revoked' => 'logout',
        'revoked_all' => 'logout_todos',
    ];

    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Alias curto ('user'|'customer'), não o nome da classe — ver AuditLog::ENTIDADES.
            $table->string('ator_type', 20)->nullable();
            $table->unsignedBigInteger('ator_id')->nullable();
            // Snapshot: continua legível se o autor for renomeado/desativado.
            $table->string('ator_nome')->nullable();
            $table->string('acao', 40);
            $table->string('entidade_type', 40)->nullable();
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->string('entidade_label')->nullable();
            $table->jsonb('alteracoes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Toda consulta tem período obrigatório; o resto refina dentro dele.
            $table->index('created_at');
            $table->index(['ator_type', 'ator_id', 'created_at']);
            $table->index(['entidade_type', 'entidade_id']);
            $table->index(['acao', 'created_at']);
        });

        if (Schema::hasTable('token_audit_logs')) {
            DB::table('token_audit_logs')
                ->leftJoin('users', fn ($j) => $j->on('users.id', '=', 'token_audit_logs.tokenable_id')
                    ->where('token_audit_logs.tokenable_type', 'App\\Models\\User'))
                ->leftJoin('customers', fn ($j) => $j->on('customers.id', '=', 'token_audit_logs.tokenable_id')
                    ->where('token_audit_logs.tokenable_type', 'App\\Models\\Customer'))
                ->orderBy('token_audit_logs.id')
                ->select([
                    'token_audit_logs.*',
                    'users.name as user_name',
                    'customers.name as customer_name',
                ])
                ->chunk(1000, function ($linhas) {
                    DB::table('audit_logs')->insert($linhas->map(fn ($l) => [
                        'ator_type' => match ($l->tokenable_type) {
                            'App\\Models\\User' => 'user',
                            'App\\Models\\Customer' => 'customer',
                            default => null,
                        },
                        'ator_id' => $l->tokenable_id,
                        'ator_nome' => $l->user_name ?? $l->customer_name,
                        'acao' => self::ACOES_TOKEN[$l->action] ?? $l->action,
                        'ip_address' => $l->ip_address,
                        'user_agent' => $l->user_agent,
                        'created_at' => $l->created_at,
                    ])->all());
                });

            Schema::drop('token_audit_logs');
        }
    }

    public function down(): void
    {
        Schema::create('token_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('tokenable');
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $inverso = array_flip(self::ACOES_TOKEN);

        DB::table('audit_logs')->whereIn('acao', array_keys($inverso))->orderBy('id')
            ->chunk(1000, fn ($linhas) => DB::table('token_audit_logs')->insert($linhas->map(fn ($l) => [
                'tokenable_type' => $l->ator_type === 'customer' ? 'App\\Models\\Customer' : 'App\\Models\\User',
                'tokenable_id' => $l->ator_id,
                'action' => $inverso[$l->acao],
                'ip_address' => $l->ip_address,
                'user_agent' => $l->user_agent,
                'created_at' => $l->created_at,
            ])->all()));

        Schema::dropIfExists('audit_logs');
    }
};
