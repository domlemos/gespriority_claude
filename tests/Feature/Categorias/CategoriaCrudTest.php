<?php

namespace Tests\Feature\Categorias;

use App\Models\Categoria;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriaCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_staff_with_view_permission_can_list_categorias(): void
    {
        Categoria::factory()->count(2)->create();
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/categorias', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_with_view_permission_can_view_a_single_categoria(): void
    {
        $categoria = Categoria::factory()->create(['nome' => 'Hardware']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson("/api/categorias/{$categoria->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'Hardware');
    }

    public function test_staff_without_view_permission_cannot_list_categorias(): void
    {
        $token = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/categorias', $this->authHeader($token))->assertStatus(403);
    }

    public function test_can_filter_categorias_by_partial_nome(): void
    {
        Categoria::factory()->create(['nome' => 'Hardware']);
        Categoria::factory()->create(['nome' => 'Software']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/categorias?nome=Hard', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Hardware');
    }

    public function test_can_filter_categorias_by_ativo_true(): void
    {
        Categoria::factory()->create(['nome' => 'Ativa', 'ativo' => true]);
        Categoria::factory()->create(['nome' => 'Inativa', 'ativo' => false]);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/categorias?ativo=1', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Ativa');
    }

    public function test_can_filter_categorias_by_ativo_false(): void
    {
        Categoria::factory()->create(['nome' => 'Ativa', 'ativo' => true]);
        Categoria::factory()->create(['nome' => 'Inativa', 'ativo' => false]);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/categorias?ativo=0', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Inativa');
    }

    public function test_admin_can_create_a_categoria(): void
    {
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/categorias', ['nome' => 'Hardware'], $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.nome', 'Hardware')
            ->assertJsonPath('data.ativo', true);

        $this->assertDatabaseHas('categorias', ['nome' => 'Hardware', 'ativo' => true]);
    }

    public function test_view_only_permission_cannot_create_a_categoria(): void
    {
        $token = $this->staffToken(['categorias.view']);

        $this->postJson('/api/categorias', ['nome' => 'Hardware'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_creating_categoria_requires_nome(): void
    {
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/categorias', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_creating_categoria_rejects_duplicate_nome(): void
    {
        Categoria::factory()->create(['nome' => 'Hardware']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/categorias', ['nome' => 'Hardware'], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_admin_can_update_a_categoria(): void
    {
        $categoria = Categoria::factory()->create(['nome' => 'Old Name']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->putJson("/api/categorias/{$categoria->id}", ['nome' => 'New Name'], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'New Name');
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'nome' => 'New Name']);
    }

    public function test_updating_a_categoria_does_not_collide_with_itself_on_uniqueness(): void
    {
        $categoria = Categoria::factory()->create(['nome' => 'Hardware']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->putJson("/api/categorias/{$categoria->id}", ['nome' => 'Hardware'], $this->authHeader($token));

        $response->assertOk();
    }

    public function test_view_only_permission_cannot_update_a_categoria(): void
    {
        $categoria = Categoria::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->putJson("/api/categorias/{$categoria->id}", ['nome' => 'New Name'], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_admin_can_delete_a_categoria_without_subcategorias(): void
    {
        $categoria = Categoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->deleteJson("/api/categorias/{$categoria->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
    }

    public function test_cannot_delete_a_categoria_that_has_subcategorias(): void
    {
        $categoria = Categoria::factory()->create();
        Subcategoria::factory()->create(['categoria_id' => $categoria->id]);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->deleteJson("/api/categorias/{$categoria->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('categorias', ['id' => $categoria->id]);
    }

    public function test_view_only_permission_cannot_delete_a_categoria(): void
    {
        $categoria = Categoria::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->deleteJson("/api/categorias/{$categoria->id}", [], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_categorias(): void
    {
        $this->getJson('/api/categorias')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_categorias(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/categorias', $this->authHeader($token))->assertStatus(401);
    }
}
