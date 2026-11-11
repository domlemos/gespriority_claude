<?php

namespace Tests\Feature\Incidentes;

use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\Item;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteMensagemEncerramentoTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['slug' => 'tickets.manage'], ['name' => 'tickets.manage'])
        );

        $this->staff = User::factory()->create();
        $this->staff->roles()->attach($role);
        $this->token = $this->staff->createToken('spa', ['staff'], now()->addMinutes(120))->plainTextToken;
    }

    private function incidente(string $status = 'em_andamento'): Incidente
    {
        return Incidente::factory()->create([
            'item_id' => Item::factory()->create(['prioridade_padrao' => 'media'])->id,
            'prioridade' => 'media',
            'status' => $status,
        ]);
    }

    private function atualizar(Incidente $incidente, array $payload)
    {
        return $this->putJson("/api/incidentes/{$incidente->id}", $payload, ['Authorization' => "Bearer {$this->token}"]);
    }

    public function test_closing_requires_a_closing_message(): void
    {
        $incidente = $this->incidente();

        $this->atualizar($incidente, ['status' => 'fechado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mensagem_encerramento');

        $this->assertSame('em_andamento', $incidente->fresh()->status);
    }

    public function test_closing_records_the_message_as_a_closing_entry_by_the_staff_user(): void
    {
        $incidente = $this->incidente();

        $this->atualizar($incidente, ['status' => 'fechado', 'mensagem_encerramento' => 'Fonte normalizada.'])->assertOk();

        $this->assertSame('fechado', $incidente->fresh()->status);
        $this->assertDatabaseHas('incidente_descricoes', [
            'incidente_id' => $incidente->id,
            'user_id' => $this->staff->id,
            'customer_id' => null,
            'tipo' => IncidenteDescricao::TIPO_ENCERRAMENTO,
            'descricao' => 'Fonte normalizada.',
        ]);
    }

    public function test_message_is_not_required_for_other_statuses_nor_when_already_closed(): void
    {
        $aberto = $this->incidente();
        $this->atualizar($aberto, ['status' => 'resolvido'])->assertOk();

        // O formulário reenvia o status a cada salvar — salvar de novo um
        // chamado já fechado não é um novo encerramento.
        $fechado = $this->incidente('fechado');
        $this->atualizar($fechado, ['status' => 'fechado', 'responsavel_id' => null])->assertOk();

        $this->assertSame(0, IncidenteDescricao::query()->where('tipo', IncidenteDescricao::TIPO_ENCERRAMENTO)->count());
    }

    public function test_message_sent_without_closing_is_ignored(): void
    {
        $incidente = $this->incidente();

        $this->atualizar($incidente, ['status' => 'pendente', 'mensagem_encerramento' => 'Não deveria gravar.'])->assertOk();

        $this->assertSame(0, IncidenteDescricao::query()->where('tipo', IncidenteDescricao::TIPO_ENCERRAMENTO)->count());
    }

    public function test_closing_entry_cannot_be_edited_or_deleted(): void
    {
        $incidente = $this->incidente();
        $this->atualizar($incidente, ['status' => 'fechado', 'mensagem_encerramento' => 'Fonte normalizada.'])->assertOk();
        $entrada = IncidenteDescricao::query()->where('tipo', IncidenteDescricao::TIPO_ENCERRAMENTO)->sole();
        $headers = ['Authorization' => "Bearer {$this->token}"];

        $this->putJson("/api/incidentes/{$incidente->id}/descricoes/{$entrada->id}", ['descricao' => 'Outra'], $headers)
            ->assertForbidden();
        $this->deleteJson("/api/incidentes/{$incidente->id}/descricoes/{$entrada->id}", [], $headers)
            ->assertForbidden();
    }
}
