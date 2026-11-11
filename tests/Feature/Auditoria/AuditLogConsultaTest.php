<?php

namespace Tests\Feature\Auditoria;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogConsultaTest extends TestCase
{
    use RefreshDatabase;

    private function headers(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return ['Authorization' => 'Bearer '.$user->createToken('spa', ['staff'], now()->addHour())->plainTextToken];
    }

    private function log(array $attrs): AuditLog
    {
        return AuditLog::query()->forceCreate([
            'acao' => 'criado',
            'ator_type' => 'user',
            'ator_id' => 1,
            'ator_nome' => 'Ana',
            'entidade_type' => 'categoria',
            'entidade_id' => 10,
            'entidade_label' => 'Rede',
            'created_at' => '2026-11-10 15:00:00',
            ...$attrs,
        ]);
    }

    public function test_requires_the_auditoria_view_permission(): void
    {
        $this->getJson('/api/audit-logs?data_inicio=2026-11-01&data_fim=2026-11-11', $this->headers(['users.manage']))
            ->assertForbidden();
    }

    public function test_period_is_required_and_capped(): void
    {
        $h = $this->headers(['auditoria.view']);

        $this->getJson('/api/audit-logs', $h)->assertStatus(422)->assertJsonValidationErrors(['data_inicio', 'data_fim']);
        $this->getJson('/api/audit-logs?data_inicio=2026-01-01&data_fim=2026-11-11', $h)
            ->assertStatus(422)->assertJsonValidationErrors(['data_fim']);
    }

    public function test_filters_by_period_actor_action_entity_and_text(): void
    {
        $h = $this->headers(['auditoria.view']);
        $alvo = $this->log([]);
        $this->log(['ator_id' => 2, 'ator_nome' => 'Bruno']);
        $this->log(['acao' => 'excluido']);
        $this->log(['entidade_type' => 'item']);
        $this->log(['entidade_label' => 'Hardware']);
        // Fora do período (dia anterior em Brasília).
        $this->log(['created_at' => '2026-11-10 02:00:00']);

        $resp = $this->getJson('/api/audit-logs?'.http_build_query([
            'data_inicio' => '2026-11-10',
            'data_fim' => '2026-11-10',
            'user_id' => 1,
            'acao' => ['criado'],
            'entidade_type' => 'categoria',
            'busca' => 'red',
        ]), $h)->assertOk();

        $resp->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alvo->id)
            ->assertJsonPath('data.0.acao_rotulo', 'Criou')
            ->assertJsonPath('data.0.entidade.type_rotulo', 'Categoria')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_options_endpoint_lists_actions_and_entities(): void
    {
        $this->getJson('/api/audit-logs/opcoes', $this->headers(['auditoria.view']))
            ->assertOk()
            ->assertJsonPath('retencao_meses', 6)
            ->assertJsonFragment(['value' => 'login', 'title' => 'Entrou no sistema']);
    }
}
