<?php

namespace Tests\Feature\Categorias;

use App\Models\Customer;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemCrudTest extends TestCase
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

    public function test_staff_with_view_permission_can_list_itens(): void
    {
        Item::factory()->count(2)->create();
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/itens', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_staff_with_view_permission_can_view_a_single_item_with_subcategoria_loaded(): void
    {
        $subcategoria = Subcategoria::factory()->create(['nome' => 'Impressora']);
        $item = Item::factory()->create(['subcategoria_id' => $subcategoria->id, 'nome' => 'Sem toner']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson("/api/itens/{$item->id}", $this->authHeader($token));

        $response->assertOk()
            ->assertJsonPath('data.nome', 'Sem toner')
            ->assertJsonPath('data.subcategoria.nome', 'Impressora');
    }

    public function test_staff_without_view_permission_cannot_list_itens(): void
    {
        $token = $this->staffToken(['some.other.permission']);

        $this->getJson('/api/itens', $this->authHeader($token))->assertStatus(403);
    }

    public function test_can_filter_itens_by_partial_nome(): void
    {
        Item::factory()->create(['nome' => 'Sem toner']);
        Item::factory()->create(['nome' => 'Tela quebrada']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/itens?nome=toner', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Sem toner');
    }

    public function test_can_filter_itens_by_subcategoria_id(): void
    {
        $subcategoriaA = Subcategoria::factory()->create();
        $subcategoriaB = Subcategoria::factory()->create();
        Item::factory()->create(['subcategoria_id' => $subcategoriaA->id, 'nome' => 'Sem toner']);
        Item::factory()->create(['subcategoria_id' => $subcategoriaB->id, 'nome' => 'Tela quebrada']);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson("/api/itens?subcategoria_id={$subcategoriaA->id}", $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Sem toner');
    }

    public function test_filtering_itens_rejects_invalid_subcategoria_id(): void
    {
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/itens?subcategoria_id=999999', $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('subcategoria_id');
    }

    public function test_can_filter_itens_by_ativo_true(): void
    {
        Item::factory()->create(['nome' => 'Ativo', 'ativo' => true]);
        Item::factory()->create(['nome' => 'Inativo', 'ativo' => false]);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/itens?ativo=1', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Ativo');
    }

    public function test_can_filter_itens_by_ativo_false(): void
    {
        Item::factory()->create(['nome' => 'Ativo', 'ativo' => true]);
        Item::factory()->create(['nome' => 'Inativo', 'ativo' => false]);
        $token = $this->staffToken(['categorias.view']);

        $response = $this->getJson('/api/itens?ativo=0', $this->authHeader($token));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Inativo');
    }

    public function test_admin_can_create_an_item(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoria->id,
            'nome' => 'Sem toner',
        ], $this->authHeader($token));

        $response->assertCreated()
            ->assertJsonPath('data.nome', 'Sem toner')
            ->assertJsonPath('data.subcategoria_id', $subcategoria->id)
            ->assertJsonPath('data.ativo', true);

        $this->assertDatabaseHas('itens', ['nome' => 'Sem toner', 'subcategoria_id' => $subcategoria->id]);
    }

    public function test_view_only_permission_cannot_create_an_item(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoria->id,
            'nome' => 'Sem toner',
        ], $this->authHeader($token))->assertStatus(403);
    }

    public function test_creating_item_requires_subcategoria_id_and_nome(): void
    {
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors(['subcategoria_id', 'nome']);
    }

    public function test_creating_item_rejects_nonexistent_subcategoria_id(): void
    {
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => 999999,
            'nome' => 'Sem toner',
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('subcategoria_id');
    }

    public function test_creating_item_rejects_duplicate_nome_within_the_same_subcategoria(): void
    {
        $subcategoria = Subcategoria::factory()->create();
        Item::factory()->create(['subcategoria_id' => $subcategoria->id, 'nome' => 'Sem toner']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoria->id,
            'nome' => 'Sem toner',
        ], $this->authHeader($token));

        $response->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_can_create_same_nome_item_under_different_subcategorias(): void
    {
        $subcategoriaA = Subcategoria::factory()->create();
        $subcategoriaB = Subcategoria::factory()->create();
        Item::factory()->create(['subcategoria_id' => $subcategoriaA->id, 'nome' => 'Não liga']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->postJson('/api/itens', [
            'subcategoria_id' => $subcategoriaB->id,
            'nome' => 'Não liga',
        ], $this->authHeader($token));

        $response->assertCreated();
    }

    public function test_admin_can_update_an_item(): void
    {
        $item = Item::factory()->create(['nome' => 'Old Name']);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->putJson("/api/itens/{$item->id}", [
            'subcategoria_id' => $item->subcategoria_id,
            'nome' => 'New Name',
        ], $this->authHeader($token));

        $response->assertOk()->assertJsonPath('data.nome', 'New Name');
        $this->assertDatabaseHas('itens', ['id' => $item->id, 'nome' => 'New Name']);
    }

    public function test_view_only_permission_cannot_update_an_item(): void
    {
        $item = Item::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->putJson("/api/itens/{$item->id}", [
            'subcategoria_id' => $item->subcategoria_id,
            'nome' => 'New Name',
        ], $this->authHeader($token))->assertStatus(403);
    }

    public function test_admin_can_delete_an_item(): void
    {
        $item = Item::factory()->create();
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->deleteJson("/api/itens/{$item->id}", [], $this->authHeader($token));

        $response->assertNoContent();
        $this->assertDatabaseMissing('itens', ['id' => $item->id]);
    }

    public function test_cannot_delete_an_item_that_has_incidentes(): void
    {
        $item = Item::factory()->create();
        Incidente::factory()->create(['item_id' => $item->id]);
        $token = $this->staffToken(['categorias.manage']);

        $response = $this->deleteJson("/api/itens/{$item->id}", [], $this->authHeader($token));

        $response->assertStatus(409);
        $this->assertDatabaseHas('itens', ['id' => $item->id]);
    }

    public function test_view_only_permission_cannot_delete_an_item(): void
    {
        $item = Item::factory()->create();
        $token = $this->staffToken(['categorias.view']);

        $this->deleteJson("/api/itens/{$item->id}", [], $this->authHeader($token))
            ->assertStatus(403);
    }

    public function test_guests_cannot_access_itens(): void
    {
        $this->getJson('/api/itens')->assertStatus(401);
    }

    public function test_customer_guard_cannot_access_itens(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('spa', ['customer'], now()->addMinutes(240))->plainTextToken;

        $this->getJson('/api/itens', $this->authHeader($token))->assertStatus(401);
    }
}
