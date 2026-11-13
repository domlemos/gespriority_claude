<?php

namespace Tests\Feature\Incidentes;

use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Relatorios\TituloRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidenteStatusAguardandoTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::factory()->create(['slug' => 'admin']);
        foreach (['tickets.view', 'tickets.manage'] as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $this->token = $user->createToken('spa', ['staff'], now()->addHour())->plainTextToken;
    }

    private function atualizar(Incidente $incidente, array $payload)
    {
        return $this->putJson("/api/incidentes/{$incidente->id}", $payload, ['Authorization' => "Bearer {$this->token}"]);
    }

    public function test_new_statuses_are_accepted_and_labelled(): void
    {
        $this->assertSame('Aguardando Interno', TituloRelatorio::STATUS['aguardando_interno']);
        $this->assertSame('Aguardando Externo', TituloRelatorio::STATUS['aguardando_externo']);

        foreach (['aguardando_interno', 'aguardando_externo'] as $status) {
            $incidente = Incidente::factory()->create(['status' => 'em_andamento']);

            $this->atualizar($incidente, ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);

            // Feed registra a troca como qualquer outra.
            $this->assertTrue($incidente->descricoes()->where('tipo', 'alteracao')->where('descricao', 'like', "Campo 'Status' alterado de 'em_andamento' para '{$status}'%")->exists());
        }

        $this->atualizar(Incidente::factory()->create(), ['status' => 'aguardando'])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_waiting_statuses_have_no_sla_effect(): void
    {
        foreach (['aguardando_interno', 'aguardando_externo'] as $status) {
            $incidente = Incidente::factory()->create(['status' => 'em_andamento']);
            $incidente->forceFill(['prazo_resolucao' => now()->subHour(), 'respondido_em' => now()->subDay()])->saveQuietly();

            $this->atualizar($incidente, ['status' => $status])->assertOk();

            $fresh = $incidente->fresh();
            // Não conclui, não pausa: o prazo segue correndo contra "agora".
            $this->assertNull($fresh->concluido_em);
            $this->assertSame(Incidente::SLA_ESTOURADO, $fresh->statusSlaResolucao());
            $this->assertSame(0, IncidenteEvento::query()->where('incidente_id', $incidente->id)->count());
        }
    }

    public function test_leaving_a_waiting_status_is_not_a_reopen(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'aguardando_externo']);

        $this->atualizar($incidente, ['status' => 'em_andamento'])->assertOk();

        $this->assertSame(0, IncidenteEvento::query()->where('incidente_id', $incidente->id)->where('tipo', IncidenteEvento::TIPO_REABERTO)->count());
    }

    public function test_dashboard_filters_by_the_new_statuses(): void
    {
        Incidente::factory()->create(['status' => 'aguardando_interno']);
        Incidente::factory()->create(['status' => 'aguardando_externo']);
        Incidente::factory()->create(['status' => 'pendente']);

        $this->getJson('/api/dashboard/incidentes?status=aguardando_externo', ['Authorization' => "Bearer {$this->token}"])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'aguardando_externo');
    }
}
