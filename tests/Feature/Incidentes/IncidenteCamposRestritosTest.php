<?php

namespace Tests\Feature\Incidentes;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncidenteCamposRestritosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string[]  $permissionSlugs
     */
    private function staffToken(array $permissionSlugs): string
    {
        $role = Role::factory()->create();

        foreach ($permissionSlugs as $slug) {
            $permission = Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->attach($permission);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function incidente(): Incidente
    {
        $item = Item::factory()->create(['prioridade_padrao' => 'media']);

        return Incidente::factory()->create([
            'titulo' => 'Original',
            'origem' => 'portal',
            'item_id' => $item->id,
            'prioridade' => 'media',
            'status' => 'aberto',
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function camposRestritos(): array
    {
        return [
            'titulo' => ['titulo', 'Novo título'],
            'origem' => ['origem', 'telefone'],
            'customer_id' => ['customer_id', null],
        ];
    }

    #[DataProvider('camposRestritos')]
    public function test_analista_cannot_change_a_restricted_field(string $campo, mixed $novoValor): void
    {
        $incidente = $this->incidente();
        $novoValor ??= Customer::factory()->create()->id;
        $token = $this->staffToken(['tickets.manage']);

        $this->putJson(
            "/api/incidentes/{$incidente->id}",
            [$campo => $novoValor],
            $this->authHeader($token)
        )->assertStatus(422)->assertJsonValidationErrors($campo);

        $this->assertSame($incidente->{$campo}, $incidente->fresh()->{$campo});
    }

    public function test_analista_can_change_classificacao_encaminhamento_and_status(): void
    {
        $incidente = $this->incidente();
        $novoItem = Item::factory()->create(['prioridade_padrao' => 'alta']);
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $token = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", [
            'item_id' => $novoItem->id,
            'grupo_solucao_id' => $grupo->id,
            'responsavel_id' => $responsavel->id,
            'status' => 'em_andamento',
        ], $this->authHeader($token))->assertOk();

        $fresh = $incidente->fresh();
        $this->assertSame($novoItem->id, $fresh->item_id);
        $this->assertSame($grupo->id, $fresh->grupo_solucao_id);
        $this->assertSame($responsavel->id, $fresh->responsavel_id);
        $this->assertSame('em_andamento', $fresh->status);
    }

    public function test_analista_can_resend_restricted_fields_unchanged(): void
    {
        // O formulário do front sempre reenvia todos os campos — reenviar o
        // mesmo valor não pode ser tratado como tentativa de alteração.
        $incidente = $this->incidente();
        $token = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", [
            'customer_id' => $incidente->customer_id,
            'titulo' => 'Original',
            'origem' => 'portal',
            'status' => 'resolvido',
        ], $this->authHeader($token))->assertOk();

        $this->assertSame('resolvido', $incidente->fresh()->status);
    }

    public function test_a_rejected_restricted_field_blocks_the_whole_update(): void
    {
        $incidente = $this->incidente();
        $token = $this->staffToken(['tickets.manage']);

        $this->putJson("/api/incidentes/{$incidente->id}", [
            'titulo' => 'Novo título',
            'status' => 'resolvido',
        ], $this->authHeader($token))->assertStatus(422);

        $this->assertSame('aberto', $incidente->fresh()->status);
    }

    public function test_user_with_edit_all_can_change_restricted_fields(): void
    {
        $incidente = $this->incidente();
        $novoCliente = Customer::factory()->create();
        $token = $this->staffToken(['tickets.manage', 'tickets.edit_all']);

        $this->putJson("/api/incidentes/{$incidente->id}", [
            'customer_id' => $novoCliente->id,
            'titulo' => 'Novo título',
            'origem' => 'telefone',
        ], $this->authHeader($token))->assertOk();

        $fresh = $incidente->fresh();
        $this->assertSame($novoCliente->id, $fresh->customer_id);
        $this->assertSame('Novo título', $fresh->titulo);
        $this->assertSame('telefone', $fresh->origem);
    }

    public function test_analista_can_still_create_an_incidente_with_all_fields(): void
    {
        $token = $this->staffToken(['tickets.manage']);

        $this->postJson('/api/incidentes', [
            'customer_id' => Customer::factory()->create()->id,
            'item_id' => Item::factory()->create()->id,
            'titulo' => 'Impressora não liga',
            'descricao' => 'Tentei ligar e nada acontece.',
            'origem' => 'telefone',
        ], $this->authHeader($token))->assertCreated();
    }
}
