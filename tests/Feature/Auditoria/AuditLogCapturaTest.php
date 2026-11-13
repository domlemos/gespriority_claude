<?php

namespace Tests\Feature\Auditoria;

use App\Models\AuditLog;
use App\Models\Categoria;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsaSessaoSpa;
use Tests\TestCase;

class AuditLogCapturaTest extends TestCase
{
    use RefreshDatabase, UsaSessaoSpa;

    private function staff(array $permissionSlugs): User
    {
        $role = Role::factory()->create(['name' => 'Papel Teste']);

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }

        $user = User::factory()->create(['name' => 'Ana Admin']);
        $user->roles()->attach($role);

        return $user;
    }

    private function headers(User|Customer $quem, string $ability = 'staff'): array
    {
        return ['Authorization' => 'Bearer '.$quem->createToken('spa', [$ability], now()->addHour())->plainTextToken];
    }

    public function test_models_created_without_an_authenticated_user_are_not_logged(): void
    {
        Categoria::factory()->create();
        User::factory()->create();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_crud_through_the_api_logs_create_update_and_delete_with_diff(): void
    {
        $admin = $this->staff(['categorias.manage']);
        $h = $this->headers($admin);

        $id = $this->postJson('/api/categorias', ['nome' => 'Rede', 'ativo' => true], $h)->assertCreated()->json('data.id');
        $this->putJson("/api/categorias/{$id}", ['nome' => 'Redes', 'ativo' => true], $h)->assertOk();
        $this->deleteJson("/api/categorias/{$id}", [], $h)->assertNoContent();

        $logs = AuditLog::query()->where('entidade_type', 'categoria')->orderBy('id')->get();

        $this->assertSame(['criado', 'atualizado', 'excluido'], $logs->pluck('acao')->all());
        $this->assertTrue($logs->every(fn (AuditLog $l) => $l->ator_type === 'user' && $l->ator_id === $admin->id && $l->ator_nome === 'Ana Admin'));
        $this->assertSame(['de' => null, 'para' => 'Rede'], $logs[0]->alteracoes['nome']);
        $this->assertSame(['nome' => ['de' => 'Rede', 'para' => 'Redes']], $logs[1]->alteracoes);
        $this->assertSame('Redes', $logs[2]->entidade_label);
        $this->assertSame((int) $id, $logs[2]->entidade_id);
    }

    public function test_update_without_real_changes_is_not_logged(): void
    {
        $admin = $this->staff(['categorias.manage']);
        $categoria = Categoria::factory()->create(['nome' => 'Rede', 'ativo' => true]);

        $this->putJson("/api/categorias/{$categoria->id}", ['nome' => 'Rede', 'ativo' => true], $this->headers($admin))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_password_change_is_flagged_without_storing_the_hash(): void
    {
        $admin = $this->staff(['users.manage']);
        $alvo = User::factory()->create();

        $this->putJson("/api/users/{$alvo->id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'grupo_solucao_id' => $alvo->grupo_solucao_id,
            'password' => 'nova-senha-123',
        ], $this->headers($admin))->assertOk();

        $log = AuditLog::query()->where('entidade_type', 'user')->where('entidade_id', $alvo->id)->sole();

        $this->assertSame(['de' => null, 'para' => '(alterada)'], $log->alteracoes['senha']);
        $this->assertArrayNotHasKey('password', $log->alteracoes);
        $this->assertStringNotContainsString('$2y$', json_encode($log->alteracoes));
    }

    public function test_role_sync_is_logged_as_a_relation_diff(): void
    {
        $admin = $this->staff(['users.manage']);
        $alvo = User::factory()->create();
        $novo = Role::factory()->create(['name' => 'Supervisor']);

        $this->putJson("/api/users/{$alvo->id}", [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'grupo_solucao_id' => $alvo->grupo_solucao_id,
            'role_ids' => [$novo->id],
        ], $this->headers($admin))->assertOk();

        $log = AuditLog::query()->where('entidade_id', $alvo->id)->where('entidade_type', 'user')->sole();
        $this->assertSame(['papeis' => ['de' => [], 'para' => ['Supervisor']]], $log->alteracoes);
    }

    public function test_group_permission_overrides_are_logged(): void
    {
        $admin = $this->staff(['grupos_solucao.manage']);
        $grupo = GrupoSolucao::factory()->create();
        $perm = Permission::factory()->create(['slug' => 'tickets.assign']);

        $this->putJson("/api/grupos-solucao/{$grupo->id}/permissoes", ['liberadas' => [$perm->id]], $this->headers($admin))->assertOk();

        $log = AuditLog::query()->where('entidade_type', 'grupo_solucao')->sole();
        $this->assertSame(['permissoes_liberadas' => ['de' => [], 'para' => ['tickets.assign']]], $log->alteracoes);
    }

    public function test_ticket_changes_store_only_the_changed_field_names(): void
    {
        $user = User::factory()->create();
        $incidente = Incidente::factory()->create(['titulo' => 'Antigo']);
        Sanctum::actingAs($user, ['staff'], 'web');

        $incidente->update(['titulo' => 'Novo']);
        // Coluna de sistema (fora do fillable) não gera linha.
        $incidente->forceFill(['concluido_em' => now()])->save();

        $log = AuditLog::query()->where('entidade_type', 'incidente')->sole();
        $this->assertSame(['campos' => ['titulo']], $log->alteracoes);
        $this->assertSame("#{$incidente->id} Novo", $log->entidade_label);
    }

    public function test_only_comments_are_logged_from_the_ticket_feed_and_without_their_text(): void
    {
        $user = User::factory()->create();
        $incidente = Incidente::factory()->create();
        Sanctum::actingAs($user, ['staff'], 'web');

        IncidenteDescricao::query()->create(['incidente_id' => $incidente->id, 'user_id' => $user->id, 'tipo' => 'comentario', 'descricao' => 'segredo do cliente']);
        IncidenteDescricao::query()->create(['incidente_id' => $incidente->id, 'user_id' => $user->id, 'tipo' => 'escalonamento', 'descricao' => 'sistema']);

        $log = AuditLog::query()->where('entidade_type', 'incidente_descricao')->sole();
        $this->assertNull($log->alteracoes);
        $this->assertSame("Chamado #{$incidente->id}", $log->entidade_label);
    }

    public function test_login_success_and_failure_are_logged(): void
    {
        $user = User::factory()->create(['email' => 'ana@ex.com', 'password' => 'segredo123']);

        $this->spa('POST', '/api/login', ['email' => 'ana@ex.com', 'password' => 'errada'])->assertStatus(422);
        $this->spa('POST', '/api/login', ['email' => 'ninguem@ex.com', 'password' => 'errada'])->assertStatus(422);
        $this->spa('POST', '/api/login', ['email' => 'ana@ex.com', 'password' => 'segredo123'])->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();

        $this->assertSame(['login_falhou', 'login_falhou', 'login'], $logs->pluck('acao')->all());
        $this->assertSame($user->id, $logs[0]->ator_id);
        $this->assertSame('ana@ex.com', $logs[0]->ator_nome);
        $this->assertNull($logs[1]->ator_id);
        $this->assertSame('ninguem@ex.com', $logs[1]->ator_nome);
        $this->assertSame($user->id, $logs[2]->ator_id);
        $this->assertSame($user->name, $logs[2]->ator_nome);
    }

    public function test_unauthenticated_route_does_not_borrow_another_profile_session_as_actor(): void
    {
        User::factory()->create(['email' => 'ana@ex.com', 'password' => 'segredo123']);
        $this->spa('POST', '/api/login', ['email' => 'ana@ex.com', 'password' => 'segredo123'])->assertOk();

        // Mesmo navegador (sessão do staff ativa) tentando o login de cliente.
        $this->spa('POST', '/api/customer/login', ['email' => 'cliente@ex.com', 'password' => 'errada'])->assertStatus(422);

        $log = AuditLog::query()->where('acao', 'login_falhou')->sole();
        $this->assertSame('customer', $log->ator_type);
        $this->assertSame('cliente@ex.com', $log->ator_nome);
        $this->assertNull($log->ator_id);
    }

    public function test_customer_actions_on_the_portal_are_logged_with_the_customer_as_actor(): void
    {
        $customer = Customer::factory()->create(['name' => 'Cliente Carlos']);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id]);

        $this->postJson("/api/customer/incidentes/{$incidente->id}/comentarios", ['descricao' => 'oi'], $this->headers($customer, 'customer'))
            ->assertSuccessful();

        $log = AuditLog::query()->where('entidade_type', 'incidente_descricao')->sole();
        $this->assertSame('customer', $log->ator_type);
        $this->assertSame('Cliente Carlos', $log->ator_nome);
    }

    public function test_logs_older_than_six_months_are_pruned(): void
    {
        AuditLog::query()->forceCreate(['acao' => 'login', 'created_at' => now()->subMonths(6)->subDay()]);
        AuditLog::query()->forceCreate(['acao' => 'login', 'created_at' => now()->subMonths(6)->addDay()]);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertSame(1, AuditLog::query()->count());
    }
}
