<?php

namespace Tests\Feature\Incidentes;

use App\Models\Customer;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteDescricaoCrudTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: User} */
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

    public function test_staff_with_view_permission_can_list_descricoes_of_an_incidente(): void
    {
        $incidente = Incidente::factory()->create();
        IncidenteDescricao::factory()->count(2)->create(['incidente_id' => $incidente->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_with_view_permission_can_view_a_single_descricao(): void
    {
        $incidente = Incidente::factory()->create();
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'descricao' => 'Testado e reproduzido em campo.',
        ]);
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.descricao', 'Testado e reproduzido em campo.');
    }

    public function test_staff_without_view_permission_cannot_list_descricoes(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['some.other.permission']);

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_admin_can_create_a_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            "/api/incidentes/{$incidente->id}/descricoes",
            ['descricao' => 'Cliente confirmou o problema por telefone.'],
            $this->authHeader($token)
        );

        $response->assertCreated()
            ->assertJsonPath('data.tipo', 'comentario')
            ->assertJsonPath('data.descricao', 'Cliente confirmou o problema por telefone.')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('incidente_descricoes', [
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => 'comentario',
        ]);
    }

    public function test_creating_comentario_ignores_client_provided_tipo_and_user_id(): void
    {
        $incidente = Incidente::factory()->create();
        $otherUser = User::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson(
            "/api/incidentes/{$incidente->id}/descricoes",
            [
                'descricao' => 'Tentando se passar por outro agente ou por escalonamento.',
                'tipo' => 'escalonamento',
                'user_id' => $otherUser->id,
            ],
            $this->authHeader($token)
        );

        $response->assertCreated()
            ->assertJsonPath('data.tipo', 'comentario')
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_view_only_permission_cannot_create_a_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.view']);

        $this->postJson(
            "/api/incidentes/{$incidente->id}/descricoes",
            ['descricao' => 'Não deveria conseguir.'],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_creating_comentario_requires_descricao(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);

        $response = $this->postJson("/api/incidentes/{$incidente->id}/descricoes", [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('descricao');
    }

    public function test_author_can_update_own_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
            'descricao' => 'Texto original.',
        ]);

        $response = $this->putJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            ['descricao' => 'Texto corrigido.'],
            $this->authHeader($token)
        );

        $response->assertOk()->assertJsonPath('data.descricao', 'Texto corrigido.');
    }

    public function test_staff_cannot_update_someone_elses_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create(['incidente_id' => $incidente->id]);

        $this->putJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            ['descricao' => 'Tentando editar nota de outro agente.'],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_cannot_update_an_escalonamento_entry_even_as_its_own_author(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => 'escalonamento',
        ]);

        $this->putJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            ['descricao' => 'Tentando editar log de sistema.'],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_author_can_delete_own_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
        ]);

        $response = $this->deleteJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            [],
            $this->authHeader($token)
        );

        $response->assertNoContent();
        $this->assertSoftDeleted('incidente_descricoes', ['id' => $descricao->id]);
    }

    public function test_deleted_comentario_still_appears_in_the_feed_marked_as_excluido(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage', 'tickets.view']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
        ]);

        $this->deleteJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            [],
            $this->authHeader($token)
        );

        $response = $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull($response->json('data.0.excluido_em'));
    }

    public function test_cannot_edit_an_already_deleted_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
        ]);
        $descricao->delete();

        $this->putJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            ['descricao' => 'Tentando editar depois de excluído.'],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_cannot_delete_an_already_deleted_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
        ]);
        $descricao->delete();

        $this->deleteJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            [],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_descricao_still_shows_author_name_after_the_user_is_deactivated(): void
    {
        $incidente = Incidente::factory()->create();
        $autor = User::factory()->create(['name' => 'Ana Souza']);
        IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor->id,
        ]);
        $autor->delete();
        [$token] = $this->staffToken(['tickets.view']);

        $response = $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.0.user.name', 'Ana Souza');
    }

    public function test_staff_cannot_delete_someone_elses_comentario(): void
    {
        $incidente = Incidente::factory()->create();
        [$token] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create(['incidente_id' => $incidente->id]);

        $this->deleteJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            [],
            $this->authHeader($token)
        )->assertStatus(403);

        $this->assertDatabaseHas('incidente_descricoes', ['id' => $descricao->id]);
    }

    public function test_cannot_delete_an_escalonamento_entry_even_as_its_own_author(): void
    {
        $incidente = Incidente::factory()->create();
        [$token, $user] = $this->staffToken(['tickets.manage']);
        $descricao = IncidenteDescricao::factory()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $user->id,
            'tipo' => 'escalonamento',
        ]);

        $this->deleteJson(
            "/api/incidentes/{$incidente->id}/descricoes/{$descricao->id}",
            [],
            $this->authHeader($token)
        )->assertStatus(403);
    }

    public function test_descricao_from_a_different_incidente_is_not_found(): void
    {
        $incidenteA = Incidente::factory()->create();
        $incidenteB = Incidente::factory()->create();
        $descricao = IncidenteDescricao::factory()->create(['incidente_id' => $incidenteB->id]);
        [$token] = $this->staffToken(['tickets.view']);

        $this->getJson(
            "/api/incidentes/{$incidenteA->id}/descricoes/{$descricao->id}",
            $this->authHeader($token)
        )->assertStatus(404);
    }

    public function test_guests_cannot_access_descricoes(): void
    {
        $incidente = Incidente::factory()->create();

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes")->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_descricoes(): void
    {
        $incidente = Incidente::factory()->create();
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson("/api/incidentes/{$incidente->id}/descricoes", $this->authHeader($token))
            ->assertStatus(401);
    }
}
