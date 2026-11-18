<?php

namespace Tests\Feature\I18n;

use App\Models\Categoria;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MensagensControllersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: array<string, string>, 1: User} */
    private function staff(string $slug): array
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $token = $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;

        return [['Authorization' => "Bearer {$token}"], $user];
    }

    public function test_excluir_categoria_com_subcategorias_responde_no_idioma(): void
    {
        [$auth] = $this->staff('categorias.manage');
        $categoria = Categoria::factory()->create();
        Subcategoria::factory()->create(['categoria_id' => $categoria->id]);

        $this->deleteJson("/api/categorias/{$categoria->id}", [], $auth)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Não é possível excluir uma categoria com subcategorias vinculadas.');

        $this->deleteJson("/api/categorias/{$categoria->id}", [], $auth + ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete a category that has linked subcategories.');
    }

    public function test_reenviar_convite_de_usuario_responde_no_idioma(): void
    {
        Notification::fake();
        [$auth] = $this->staff('users.manage');
        $alvo = User::factory()->create();

        $this->postJson("/api/users/{$alvo->id}/convite", [], $auth)
            ->assertOk()
            ->assertJsonPath('message', 'Convite enviado.');

        $this->postJson("/api/users/{$alvo->id}/convite", [], $auth + ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('message', 'Invitation sent.');
    }

    public function test_desativar_a_propria_conta_responde_no_idioma(): void
    {
        [$auth, $user] = $this->staff('users.manage');

        $this->deleteJson("/api/users/{$user->id}", [], $auth)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Não é possível desativar a própria conta.');

        $this->deleteJson("/api/users/{$user->id}", [], $auth + ['Accept-Language' => 'en'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');
    }
}
