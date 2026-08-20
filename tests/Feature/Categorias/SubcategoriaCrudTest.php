<?php

namespace Tests\Feature\Categorias;

use App\Models\Categoria;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubcategoriaCrudTest extends TestCase
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

    public function test_staff_with_view_permission_can_list_subcategorias(): void
    {
        Subcategoria::factory()->count(2)->create();
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/subcategorias', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_with_view_permission_can_view_a_single_subcategoria_with_categoria_loaded(): void
    {
        $categoria = Categoria::factory()->create(['nome' => 'Hardware']);
        $subcategoria = Subcategoria::factory()->create(['categoria_id' => $categoria->id, 'nome' => 'Impressora']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson("/api/subcategorias/{$subcategoria->id}", $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.nome', 'Impressora')
            ->assertJsonPath('data.categoria.nome', 'Hardware');
    }

    public function test_staff_without_view_permission_cannot_list_subcategorias(): void
    {
        $token = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/subcategorias', $this->authHeader($token))->assertStatus(403);
    }

    public function test_can_filter_subcategorias_by_partial_nome(): void
    {
        Subcategoria::factory()->create(['nome' => 'Impressora']);
        Subcategoria::factory()->create(['nome' => 'Monitor']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/subcategorias?nome=Impr', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Impressora');
    }

    public function test_can_filter_subcategorias_by_categoria_id(): void
    {
        $categoriaA = Categoria::factory()->create();
        $categoriaB = Categoria::factory()->create();
        Subcategoria::factory()->create(['categoria_id' => $categoriaA->id, 'nome' => 'Impressora']);
        Subcategoria::factory()->create(['categoria_id' => $categoriaB->id, 'nome' => 'Monitor']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson("/api/subcategorias?categoria_id={$categoriaA->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Impressora');
    }

    public function test_filtering_subcategorias_rejects_invalid_categoria_id(): void
    {
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/subcategorias?categoria_id=999999', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('categoria_id');
    }

    public function test_can_filter_subcategorias_by_ativo_true(): void
    {
        Subcategoria::factory()->create(['nome' => 'Ativa', 'ativo' => true]);
        Subcategoria::factory()->create(['nome' => 'Inativa', 'ativo' => false]);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/subcategorias?ativo=1', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Ativa');
    }

    public function test_can_filter_subcategorias_by_ativo_false(): void
    {
        Subcategoria::factory()->create(['nome' => 'Ativa', 'ativo' => true]);
        Subcategoria::factory()->create(['nome' => 'Inativa', 'ativo' => false]);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/subcategorias?ativo=0', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Inativa');
    }

    public function test_admin_can_create_a_subcategoria(): void
    {
        $categoria = Categoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/subcategorias', [
            'categoria_id' => $categoria->id,
            'nome' => 'Impressora',
        ], $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.nome', 'Impressora')
            ->assertJsonPath('data.categoria_id', $categoria->id)
            ->assertJsonPath('data.ativo', true);

        $this->assertDatabaseHas('subcategorias', ['nome' => 'Impressora', 'categoria_id' => $categoria->id]);
    }

    public function test_view_only_permission_cannot_create_a_subcategoria(): void
    {
        $categoria = Categoria::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->postJson('/api/subcategorias', [
            'categoria_id' => $categoria->id,
            'nome' => 'Impressora',
        ], $this->authHeader($token))->assertStatus(403);
    }

    public function test_creating_subcategoria_requires_categoria_id_and_nome(): void
    {
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/subcategorias', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors(['categoria_id', 'nome']);
    }

    public function test_creating_subcategoria_rejects_nonexistent_categoria_id(): void
    {
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/subcategorias', [
            'categoria_id' => 999999,
            'nome' => 'Impressora',
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('categoria_id');
    }

    public function test_creating_subcategoria_rejects_duplicate_nome_within_the_same_categoria(): void
    {
        $categoria = Categoria::factory()->create();
        Subcategoria::factory()->create(['categoria_id' => $categoria->id, 'nome' => 'Impressora']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/subcategorias', [
            'categoria_id' => $categoria->id,
            'nome' => 'Impressora',
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_can_create_same_nome_subcategoria_under_different_categorias(): void
    {
        $categoriaA = Categoria::factory()->create();
        $categoriaB = Categoria::factory()->create();
        Subcategoria::factory()->create(['categoria_id' => $categoriaA->id, 'nome' => 'Impressora']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/subcategorias', [
            'categoria_id' => $categoriaB->id,
            'nome' => 'Impressora',
        ], $this->authHeader($token));

        $response->assertCreated();
    }

    public function test_admin_can_update_a_subcategoria(): void
    {
        $subcategoria = Subcategoria::factory()->create(['nome' => 'Old Name']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->putJson("/api/subcategorias/{$subcategoria->id}", [
            'categoria_id' => $subcategoria->categoria_id,
            'nome' => 'New Name',
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'New Name');
        $this->assertDatabaseHas('subcategorias', ['id' => $subcategoria->id, 'nome' => 'New Name']);
    }

    public function test_view_only_permission_cannot_update_a_subcategoria(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->putJson("/api/subcategorias/{$subcategoria->id}", [
            'categoria_id' => $subcategoria->categoria_id,
            'nome' => 'New Name',
        ], $this->authHeader($token))->assertStatus(403);
    }

    public function test_admin_can_delete_a_subcategoria(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->deleteJson("/api/subcategorias/{$subcategoria->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('subcategorias', ['id' => $subcategoria->id]);
    }

    public function test_cannot_delete_a_subcategoria_that_has_itens(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        Item::factory()->create(['subcategoria_id' => $subcategoria->id]);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->deleteJson("/api/subcategorias/{$subcategoria->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('subcategorias', ['id' => $subcategoria->id]);
    }

    public function test_view_only_permission_cannot_delete_a_subcategoria(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->deleteJson("/api/subcategorias/{$subcategoria->id}", [], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_subcategorias(): void
    {
        $this->getJson('/api/subcategorias')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_subcategorias(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/subcategorias', $this->authHeader($token))->assertStatus(401);
    }
}
