<?php

namespace Tests\Feature\Incidentes;

use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteResolucao;
use App\Models\Item;
use App\Models\Permission;
use App\Models\PoliticaSla;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     * @return array{0: string, 1: User}
     */
    private function staffToken(array $permissionSlugs): array
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [$token, $user];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => Customer::factory()->create()->id,
            'titulo' => 'Impressora não liga',
            'descricao' => 'Tentei ligar e nada acontece.',
            'prioridade' => 'alta',
            'origem' => 'portal',
        ], $overrides);
    }

    public function test_staff_with_view_permission_can_list_incidentes(): void
    {
        Incidente::factory()->count(2)->create();
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_can_filter_incidentes_by_status(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'resolvido']);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?status=resolvido', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'resolvido');
    }

    public function test_can_filter_incidentes_by_prioridade(): void
    {
        Incidente::factory()->create(['prioridade' => 'baixa']);
        Incidente::factory()->create(['prioridade' => 'urgente']);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?prioridade=urgente', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.prioridade', 'urgente');
    }

    public function test_can_filter_incidentes_by_origem(): void
    {
        Incidente::factory()->create(['origem' => 'portal']);
        Incidente::factory()->create(['origem' => 'telefone']);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?origem=telefone', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origem', 'telefone');
    }

    public function test_can_filter_incidentes_by_customer_id(): void
    {
        $customer = Customer::factory()->create();
        Incidente::factory()->create(['customer_id' => $customer->id]);
        Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes?customer_id={$customer->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_filter_incidentes_by_item_id(): void
    {
        $item = Item::factory()->create();
        Incidente::factory()->create(['item_id' => $item->id]);
        Incidente::factory()->create(['item_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes?item_id={$item->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_filter_incidentes_by_grupo_solucao_id(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['grupo_solucao_id' => $grupo->id]);
        Incidente::factory()->create(['grupo_solucao_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes?grupo_solucao_id={$grupo->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_filter_incidentes_by_responsavel_id(): void
    {
        $responsavel = User::factory()->create();
        Incidente::factory()->create(['responsavel_id' => $responsavel->id]);
        Incidente::factory()->create(['responsavel_id' => null]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes?responsavel_id={$responsavel->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_combine_multiple_filters(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        Incidente::factory()->create(['status' => 'aberto', 'prioridade' => 'alta', 'grupo_solucao_id' => $grupo->id]);
        Incidente::factory()->create(['status' => 'aberto', 'prioridade' => 'baixa', 'grupo_solucao_id' => $grupo->id]);
        Incidente::factory()->create(['status' => 'resolvido', 'prioridade' => 'alta', 'grupo_solucao_id' => $grupo->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson(
            "/api/incidentes?status=aberto&prioridade=alta&grupo_solucao_id={$grupo->id}",
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_listing_without_status_filter_defaults_to_aberto_em_andamento_and_pendente_only(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'em_andamento']);
        Incidente::factory()->create(['status' => 'pendente']);
        Incidente::factory()->create(['status' => 'resolvido']);
        Incidente::factory()->create(['status' => 'fechado']);
        Incidente::factory()->create(['status' => 'cancelado']);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(3, 'data');
        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertEqualsCanonicalizing(['aberto', 'em_andamento', 'pendente'], $statuses->all());
    }

    public function test_explicit_status_filter_overrides_the_default_aberto_em_andamento_restriction(): void
    {
        Incidente::factory()->create(['status' => 'aberto']);
        Incidente::factory()->create(['status' => 'fechado']);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?status=fechado', $this->authHeader($token));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'fechado');
    }

    public function test_filtering_incidentes_by_invalid_status_returns_422(): void
    {
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?status=em_orbita', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_filtering_incidentes_by_invalid_prioridade_returns_422(): void
    {
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?prioridade=gigante', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_filtering_incidentes_by_nonexistent_customer_id_returns_422(): void
    {
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson('/api/incidentes?customer_id=999999', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_staff_with_view_permission_can_view_a_single_incidente_with_relations_loaded(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ana']);
        $item = Item::factory()->create(['nome' => 'Sem toner']);
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Suporte N1']);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'grupo_solucao_id' => $grupo->id,
        ]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.customer.name', 'Ana')
            ->assertJsonPath('data.item.nome', 'Sem toner')
            ->assertJsonPath('data.grupo_solucao.nome', 'Suporte N1')
            ->assertJsonPath('data.responsavel', null);
    }

    public function test_incidente_still_shows_responsavel_name_after_the_user_is_deactivated(): void
    {
        $responsavel = User::factory()->create(['name' => 'Ana Souza']);
        $incidente = Incidente::factory()->create(['responsavel_id' => $responsavel->id]);
        $responsavel->delete();
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes/{$incidente->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.responsavel.name', 'Ana Souza');
    }

    public function test_staff_without_view_permission_cannot_list_incidentes(): void
    {
        [$token] = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/incidentes', $this->authHeader($token))->assertStatus(403);
    }

    public function test_admin_can_create_an_incidente(): void
    {
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson('/api/incidentes', $this->validPayload(), $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.titulo', 'Impressora não liga')
            ->assertJsonPath('data.prioridade', 'alta')
            ->assertJsonPath('data.origem', 'portal')
            ->assertJsonPath('data.status', 'aberto');

        $this->assertDatabaseHas('incidentes', ['titulo' => 'Impressora não liga', 'status' => 'aberto']);
    }

    public function test_creating_incidente_creates_the_first_feed_entry_from_descricao(): void
    {
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['descricao' => 'Tentei ligar e nada acontece.']),
            $this->authHeader($token)
        );

        $response->assertCreated();
        $incidenteId = $response->json('data.id');

        $this->assertDatabaseHas('incidente_descricoes', [
            'incidente_id' => $incidenteId,
            'user_id' => $user->id,
            'tipo' => 'comentario',
            'descricao' => 'Tentei ligar e nada acontece.',
        ]);
    }

    public function test_creating_incidente_does_not_persist_descricao_directly_on_the_incidente(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson('/api/incidentes', $this->validPayload(), $this->authHeader($token));

        $response->assertCreated()->assertJsonMissingPath('data.descricao');
    }

    public function test_creating_incidente_calculates_sla_deadlines_from_the_applicable_policy(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => $client->id,
            'prioridade' => 'alta',
            'tempo_resposta_minutos' => 60,
            'tempo_resolucao_minutos' => 480,
        ]);
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['customer_id' => $customer->id, 'prioridade' => 'alta']),
            $this->authHeader($token)
        );

        $response->assertCreated();
        $incidente = Incidente::find($response->json('data.id'));

        $this->assertNotNull($incidente->prazo_resposta);
        $this->assertNotNull($incidente->prazo_resolucao);
        $this->assertSame(
            $incidente->created_at->copy()->addMinutes(60)->timestamp,
            $incidente->prazo_resposta->timestamp
        );
        $this->assertSame(
            $incidente->created_at->copy()->addMinutes(480)->timestamp,
            $incidente->prazo_resolucao->timestamp
        );
    }

    public function test_incidente_resource_exposes_raw_sla_fields(): void
    {
        $client = Client::factory()->create();
        $customer = Customer::factory()->create(['client_id' => $client->id]);
        PoliticaSla::factory()->create([
            'client_id' => $client->id,
            'prioridade' => 'alta',
            'tempo_resposta_minutos' => 60,
            'tempo_resolucao_minutos' => 480,
        ]);
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['customer_id' => $customer->id, 'prioridade' => 'alta']),
            $this->authHeader($token)
        );

        $response->assertCreated();
        $this->assertNotNull($response->json('data.prazo_resposta'));
        $this->assertNotNull($response->json('data.prazo_resolucao'));
        $this->assertNull($response->json('data.respondido_em'));
        $this->assertNull($response->json('data.concluido_em'));
    }

    public function test_creating_incidente_leaves_deadlines_null_when_there_is_no_applicable_policy(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        // 'baixa' sem nenhuma PoliticaSla criada nesta suíte (ela não usa
        // PoliticasSlaSeeder — ver §3.8) => sem política aplicável.
        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['prioridade' => 'baixa']),
            $this->authHeader($token)
        );

        $response->assertCreated();
        $incidente = Incidente::find($response->json('data.id'));

        $this->assertNull($incidente->prazo_resposta);
        $this->assertNull($incidente->prazo_resolucao);
    }

    public function test_creating_incidente_falls_back_to_the_global_sla_policy_when_the_client_has_no_override(): void
    {
        PoliticaSla::factory()->create([
            'client_id' => null,
            'prioridade' => 'urgente',
            'tempo_resposta_minutos' => 15,
            'tempo_resolucao_minutos' => 240,
        ]);
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['prioridade' => 'urgente']),
            $this->authHeader($token)
        );

        $response->assertCreated();
        $incidente = Incidente::find($response->json('data.id'));

        $this->assertSame(
            $incidente->created_at->copy()->addMinutes(15)->timestamp,
            $incidente->prazo_resposta->timestamp
        );
        $this->assertSame(
            $incidente->created_at->copy()->addMinutes(240)->timestamp,
            $incidente->prazo_resolucao->timestamp
        );
    }

    public function test_creating_incidente_ignores_client_provided_status(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['status' => 'fechado']),
            $this->authHeader($token)
        );

        $response->assertCreated()->assertJsonPath('data.status', 'aberto');
    }

    public function test_creating_incidente_accepts_optional_item_grupo_and_responsavel(): void
    {
        $item = Item::factory()->create();
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson('/api/incidentes', $this->validPayload([
            'item_id' => $item->id,
            'grupo_solucao_id' => $grupo->id,
            'responsavel_id' => $responsavel->id,
        ]), $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.item.id', $item->id)
            ->assertJsonPath('data.grupo_solucao.id', $grupo->id)
            ->assertJsonPath('data.responsavel.id', $responsavel->id);
    }

    public function test_view_only_permission_cannot_create_an_incidente(): void
    {
        [$token] = $this->staffToken(['tickets.view']);

        $this->postJson('/api/incidentes', $this->validPayload(), $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_creating_incidente_requires_core_fields(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson('/api/incidentes', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors([
            'customer_id', 'titulo', 'descricao', 'prioridade', 'origem',
        ]);
    }

    public function test_creating_incidente_rejects_invalid_prioridade(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['prioridade' => 'gigante']),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('prioridade');
    }

    public function test_creating_incidente_rejects_invalid_origem(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['origem' => 'pombo-correio']),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('origem');
    }

    public function test_creating_incidente_rejects_nonexistent_customer_id(): void
    {
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            '/api/incidentes',
            $this->validPayload(['customer_id' => 999999]),
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_admin_can_partially_update_only_the_status(): void
    {
        $incidente = Incidente::factory()->create(['titulo' => 'Original', 'status' => 'aberto']);
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.status', 'em_andamento');
        $this->assertSame('Original', $incidente->fresh()->titulo);
    }

    public function test_updating_grupo_solucao_id_creates_an_escalonamento_entry(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => null]);
        $grupo = GrupoSolucao::factory()->create(['nome' => 'Suporte N2']);
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['grupo_solucao_id' => $grupo->id],
            $this->authHeader($token)
        )->assertOk();

        $this->assertDatabaseHas('incidente_descricoes', [
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => 'escalonamento',
        ]);
        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'escalonamento')->sole();
        $this->assertStringContainsString('Suporte N2', $entry->descricao);
    }

    public function test_updating_responsavel_id_creates_an_escalonamento_entry(): void
    {
        $incidente = Incidente::factory()->create(['responsavel_id' => null]);
        $responsavel = User::factory()->create(['name' => 'Carlos Souza']);
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['responsavel_id' => $responsavel->id],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'escalonamento')->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertStringContainsString('Carlos Souza', $entry->descricao);
    }

    public function test_updating_grupo_and_responsavel_together_creates_two_escalonamento_entries(): void
    {
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => null, 'responsavel_id' => null]);
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['grupo_solucao_id' => $grupo->id, 'responsavel_id' => $responsavel->id],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(
            2,
            IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'escalonamento')->count()
        );
    }

    public function test_updating_to_the_same_grupo_solucao_id_does_not_create_an_escalonamento_entry(): void
    {
        $grupo = GrupoSolucao::factory()->create();
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $grupo->id]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['grupo_solucao_id' => $grupo->id],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(0, IncidenteDescricao::where('incidente_id', $incidente->id)->count());
    }

    public function test_updating_status_does_not_create_an_escalonamento_entry(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'aberto']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(0, IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'escalonamento')->count());
    }

    public function test_alteracao_log_text_uses_brasilia_time_not_utc(): void
    {
        // App roda em UTC (config('app.timezone')) de propósito — só o
        // texto do log precisa refletir o horário de Brasília. Sem isso, o
        // texto mostraria a hora UTC (3h à frente do horário local).
        $incidente = Incidente::factory()->create(['origem' => 'portal']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['origem' => 'telefone'],
            $this->authHeader($token)
        )->assertOk();

        $horaBrasilia = Carbon::now('America/Sao_Paulo')->format('H:i');
        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString("às {$horaBrasilia} do dia", $entry->descricao);
    }

    public function test_updating_status_creates_an_alteracao_entry(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'fechado'],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertStringContainsString('resolvido', $entry->descricao);
        $this->assertStringContainsString('fechado', $entry->descricao);
    }

    public function test_resolving_an_incidente_creates_an_incidente_resolucao_record(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'em_andamento']);
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'resolvido'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertDatabaseHas('incidente_resolucoes', [
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
        ]);
        $this->assertSame(1, IncidenteResolucao::where('incidente_id', $incidente->id)->count());
    }

    public function test_resolving_again_after_reopening_creates_a_second_resolucao_record(): void
    {
        // O cenário exato que motivou essa tabela: um chamado resolvido,
        // reaberto e resolvido de novo precisa preservar as DUAS
        // resoluções, não só a mais recente. Mesmo token nas 3 chamadas de
        // propósito — 2 requisições HTTP com usuários DIFERENTES no mesmo
        // teste reaproveita o usuário resolvido na primeira (guard do
        // Sanctum em modo de teste, já visto antes nesta sessão); "autor
        // correto por evento" já é coberto por
        // test_resolving_an_incidente_creates_an_incidente_resolucao_record
        // (um só request) e pelo teste de relatório equivalente (via
        // factory, sem HTTP duplo).
        $incidente = Incidente::factory()->create(['status' => 'em_andamento']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'resolvido'], $this->authHeader($token));
        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'em_andamento'], $this->authHeader($token));
        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'resolvido'], $this->authHeader($token));

        $this->assertSame(2, IncidenteResolucao::where('incidente_id', $incidente->id)->count());
    }

    public function test_updating_to_a_non_resolvido_status_does_not_create_a_resolucao_record(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'aberto']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(0, IncidenteResolucao::where('incidente_id', $incidente->id)->count());
    }

    public function test_direct_transition_from_aberto_to_fechado_does_not_create_a_resolucao_record(): void
    {
        // Pulou 'resolvido' inteiramente — não houve resolução, só
        // fechamento direto, então não deveria criar registro.
        $incidente = Incidente::factory()->create(['status' => 'aberto']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'fechado'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(0, IncidenteResolucao::where('incidente_id', $incidente->id)->count());
    }

    public function test_resending_the_same_resolvido_status_does_not_create_a_duplicate_record(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'resolvido'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(0, IncidenteResolucao::where('incidente_id', $incidente->id)->count());
    }

    public function test_updating_titulo_creates_an_alteracao_entry(): void
    {
        $incidente = Incidente::factory()->create(['titulo' => 'Impressora não liga']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['titulo' => 'Impressora não liga - urgente'],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString('Impressora não liga', $entry->descricao);
        $this->assertStringContainsString('Impressora não liga - urgente', $entry->descricao);
    }

    public function test_updating_prioridade_creates_an_alteracao_entry(): void
    {
        $incidente = Incidente::factory()->create(['prioridade' => 'baixa']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['prioridade' => 'urgente'],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString('baixa', $entry->descricao);
        $this->assertStringContainsString('urgente', $entry->descricao);
    }

    public function test_updating_origem_creates_an_alteracao_entry(): void
    {
        $incidente = Incidente::factory()->create(['origem' => 'portal']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['origem' => 'telefone'],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString('portal', $entry->descricao);
        $this->assertStringContainsString('telefone', $entry->descricao);
    }

    public function test_updating_customer_id_creates_an_alteracao_entry_with_names(): void
    {
        $clienteAntigo = Customer::factory()->create(['name' => 'João Antigo']);
        $clienteNovo = Customer::factory()->create(['name' => 'Maria Nova']);
        $incidente = Incidente::factory()->create(['customer_id' => $clienteAntigo->id]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['customer_id' => $clienteNovo->id],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString('João Antigo', $entry->descricao);
        $this->assertStringContainsString('Maria Nova', $entry->descricao);
    }

    public function test_updating_item_id_from_null_creates_an_alteracao_entry(): void
    {
        $incidente = Incidente::factory()->create(['item_id' => null]);
        $item = Item::factory()->create(['nome' => 'Sem toner']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['item_id' => $item->id],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString('Sem toner', $entry->descricao);
    }

    public function test_updating_item_id_to_null_creates_an_alteracao_entry(): void
    {
        $item = Item::factory()->create(['nome' => 'Sem toner']);
        $incidente = Incidente::factory()->create(['item_id' => $item->id]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['item_id' => null],
            $this->authHeader($token)
        )->assertOk();

        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringContainsString('Sem toner', $entry->descricao);
    }

    public function test_updating_to_the_same_value_does_not_create_an_alteracao_entry(): void
    {
        $incidente = Incidente::factory()->create(['titulo' => 'Original']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['titulo' => 'Original'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(0, IncidenteDescricao::where('incidente_id', $incidente->id)->count());
    }

    public function test_updating_multiple_fields_creates_multiple_alteracao_entries(): void
    {
        $incidente = Incidente::factory()->create(['titulo' => 'Original', 'prioridade' => 'baixa', 'status' => 'aberto']);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['titulo' => 'Novo título', 'prioridade' => 'urgente', 'status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame(3, IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->count());
    }

    public function test_alteracao_entry_cannot_be_edited_even_by_an_admin(): void
    {
        $incidente = Incidente::factory()->create(['titulo' => 'Original']);
        [$token] = $this->staffToken(['tickets.manage']);
        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['titulo' => 'Alterado'],
            $this->authHeader($token)
        );
        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();

        $this->putJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$entry->id}",
            ['descricao' => 'Tentando editar log de sistema.'],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_alteracao_entry_cannot_be_deleted_even_by_an_admin(): void
    {
        $incidente = Incidente::factory()->create(['titulo' => 'Original']);
        [$token] = $this->staffToken(['tickets.manage']);
        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['titulo' => 'Alterado'],
            $this->authHeader($token)
        );
        $entry = IncidenteDescricao::where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();

        $this->deleteJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$entry->id}",
            [],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_updating_status_away_from_aberto_sets_respondido_em(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'aberto', 'respondido_em' => null]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertNotNull($incidente->fresh()->respondido_em);
    }

    public function test_updating_status_again_does_not_overwrite_respondido_em(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'aberto', 'respondido_em' => null]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'em_andamento'], $this->authHeader($token));
        $primeiraResposta = $incidente->fresh()->respondido_em;

        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'pendente'], $this->authHeader($token));

        $this->assertTrue($primeiraResposta->equalTo($incidente->fresh()->respondido_em));
    }

    public function test_updating_status_to_resolvido_sets_concluido_em(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'em_andamento', 'concluido_em' => null]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'resolvido'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertNotNull($incidente->fresh()->concluido_em);
    }

    public function test_updating_status_directly_from_aberto_to_resolvido_sets_both_timestamps(): void
    {
        $incidente = Incidente::factory()->create([
            'status' => 'aberto',
            'respondido_em' => null,
            'concluido_em' => null,
        ]);
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'resolvido'],
            $this->authHeader($token)
        )->assertOk();

        $fresh = $incidente->fresh();
        $this->assertNotNull($fresh->respondido_em);
        $this->assertNotNull($fresh->concluido_em);
    }

    public function test_concluido_em_is_not_overwritten_on_subsequent_status_changes(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        $incidente->forceFill(['concluido_em' => now()->subDay()])->save();
        $concluidoOriginal = $incidente->concluido_em;
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'fechado'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertTrue($concluidoOriginal->equalTo($incidente->fresh()->concluido_em));
    }

    public function test_reopening_a_concluded_incidente_clears_concluido_em(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        $incidente->forceFill(['concluido_em' => now()->subDay()])->save();
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertNull($incidente->fresh()->concluido_em);
    }

    public function test_reopening_a_concluded_incidente_does_not_clear_respondido_em(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        $incidente->forceFill([
            'respondido_em' => now()->subDays(2),
            'concluido_em' => now()->subDay(),
        ])->save();
        $respondidoOriginal = $incidente->respondido_em;
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertTrue($respondidoOriginal->equalTo($incidente->fresh()->respondido_em));
    }

    public function test_resolving_again_after_reopening_sets_a_fresh_concluido_em(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        $incidente->forceFill(['concluido_em' => now()->subWeek()])->save();
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'em_andamento'], $this->authHeader($token));
        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'resolvido'], $this->authHeader($token));

        $novoConcluido = $incidente->fresh()->concluido_em;
        $this->assertNotNull($novoConcluido);
        $this->assertTrue($novoConcluido->greaterThan(now()->subMinute()));
    }

    public function test_reopened_incidente_reevaluates_sla_status_against_now_instead_of_stale_concluido_em(): void
    {
        // Reproduz o bug real: resolvido dentro do prazo há dias, prazo já
        // passou em relação a "agora", reaberto — status de SLA precisa
        // voltar a comparar com "agora" (estourado), não ficar congelado
        // no concluido_em antigo (que diria "dentro_prazo").
        $incidente = Incidente::factory()->create(['status' => 'resolvido']);
        $incidente->forceFill([
            'prazo_resolucao' => now()->subDays(5),
            'concluido_em' => now()->subDays(6),
        ])->save();
        [$token] = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_andamento'],
            $this->authHeader($token)
        )->assertOk();

        $this->assertSame('estourado', $incidente->fresh()->statusSlaResolucao());
    }

    public function test_updating_incidente_rejects_invalid_status(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->putJson(
            "/api/incidentes/{$incidente->id}",
            ['status' => 'em_orbita'],
            $this->authHeader($token)
        );

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_view_only_permission_cannot_update_an_incidente(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.view']);

        $this->putJson("/api/incidentes/{$incidente->id}", ['status' => 'em_andamento'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_there_is_no_delete_route_for_incidentes(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        // 405, não 404: GET/PUT já registram /incidentes/{incidente}, então o
        // router reconhece a URI mas rejeita o verbo — prova que DELETE
        // realmente não está registrado (incidente é registro histórico,
        // "encerra" via status, nunca via exclusão).
        $this->deleteJson("/api/incidentes/{$incidente->id}", [], $this->authHeader($token))
            ->assertStatus(405);
    }

    public function test_guests_cannot_access_incidentes(): void
    {
        $this->getJson('/api/incidentes')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_incidentes(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/incidentes', $this->authHeader($token))->assertStatus(401);
    }
}
