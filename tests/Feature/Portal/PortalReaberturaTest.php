<?php

namespace Tests\Feature\Portal;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteEvento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalReaberturaTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken];
    }

    /** Chamado do cliente fechado há `$diasAtras` dias, com o evento `fechado` correspondente. */
    private function fechado(Customer $customer, int $diasAtras = 1, array $attrs = []): Incidente
    {
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create();

        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'fechado',
            'grupo_solucao_id' => $grupo->id,
            'responsavel_id' => $responsavel->id,
            ...$attrs,
        ]);
        $incidente->forceFill(['concluido_em' => now()->subDays($diasAtras)])->save();
        // Todo chamado real nasce com a descrição de abertura como 1ª entrada do feed.
        $incidente->descricoes()->create(['customer_id' => $customer->id, 'tipo' => 'comentario', 'descricao' => 'Abertura']);

        IncidenteEvento::query()->forceCreate([
            'incidente_id' => $incidente->id,
            'user_id' => $responsavel->id,
            'tipo' => IncidenteEvento::TIPO_FECHADO,
            'created_at' => now()->subDays($diasAtras),
        ]);

        return $incidente;
    }

    private function reabrir(Customer $customer, Incidente $incidente, ?string $motivo = 'O problema voltou.')
    {
        return $this->postJson("/api/customer/incidentes/{$incidente->id}/reabrir", ['motivo' => $motivo], $this->headers($customer));
    }

    public function test_customer_reopens_a_closed_ticket_keeping_group_and_assignee(): void
    {
        $customer = Customer::factory()->create();
        $incidente = $this->fechado($customer);
        $grupo = $incidente->grupo_solucao_id;
        $responsavel = $incidente->responsavel_id;

        $this->reabrir($customer, $incidente)
            ->assertOk()
            ->assertJsonPath('data.status', 'aberto')
            ->assertJsonPath('data.pode_reabrir', false);

        $incidente->refresh();
        $this->assertSame('aberto', $incidente->status);
        $this->assertNull($incidente->concluido_em);
        $this->assertSame($grupo, $incidente->grupo_solucao_id);
        $this->assertSame($responsavel, $incidente->responsavel_id);

        $evento = IncidenteEvento::query()->where('incidente_id', $incidente->id)->where('tipo', IncidenteEvento::TIPO_REABERTO)->sole();
        $this->assertNull($evento->user_id);
        $this->assertSame($customer->id, $evento->autor_customer_id);
        $this->assertSame($grupo, $evento->grupo_solucao_id);

        $comentario = IncidenteDescricao::query()->where('incidente_id', $incidente->id)->where('tipo', 'comentario')->latest('id')->first();
        $this->assertSame($customer->id, $comentario->customer_id);
        $this->assertStringContainsString('O problema voltou.', $comentario->descricao);

        $alteracao = IncidenteDescricao::query()->where('incidente_id', $incidente->id)->where('tipo', 'alteracao')->sole();
        $this->assertStringStartsWith("Campo 'Status' alterado de 'fechado' para 'aberto'", $alteracao->descricao);

        $log = AuditLog::query()->where('entidade_type', 'incidente')->where('entidade_id', $incidente->id)->where('acao', 'atualizado')->latest('id')->first();
        $this->assertSame('customer', $log->ator_type);
        $this->assertSame(['campos' => ['status']], $log->alteracoes);
    }

    public function test_portal_detail_shows_the_reason_but_not_the_internal_status_line(): void
    {
        $customer = Customer::factory()->create();
        $incidente = $this->fechado($customer);

        $descricoes = $this->reabrir($customer, $incidente)->assertOk()->json('data.descricoes');

        $this->assertSame(['comentario'], array_values(array_unique(array_column($descricoes, 'tipo'))));
    }

    public function test_detail_exposes_reopen_window_only_for_closed_tickets(): void
    {
        $customer = Customer::factory()->create();
        $fechado = $this->fechado($customer, 2);
        $resolvido = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'resolvido']);

        $this->getJson("/api/customer/incidentes/{$fechado->id}", $this->headers($customer))
            ->assertOk()
            ->assertJsonPath('data.pode_reabrir', true)
            ->assertJsonPath('data.reabrir_ate', fn ($v) => $v !== null);

        $this->getJson("/api/customer/incidentes/{$resolvido->id}", $this->headers($customer))
            ->assertOk()
            ->assertJsonPath('data.pode_reabrir', false)
            ->assertJsonPath('data.reabrir_ate', null);

        // Na listagem os campos não aparecem (evita consulta por linha).
        $this->getJson('/api/customer/incidentes', $this->headers($customer))
            ->assertOk()
            ->assertJsonMissingPath('data.0.pode_reabrir');
    }

    public function test_only_closed_tickets_can_be_reopened(): void
    {
        $customer = Customer::factory()->create();

        foreach (['aberto', 'em_andamento', 'pendente', 'resolvido', 'cancelado'] as $status) {
            $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => $status]);

            $this->reabrir($customer, $incidente)->assertStatus(422)->assertJsonValidationErrors(['status']);
            $this->assertSame($status, $incidente->refresh()->status);
        }
    }

    public function test_reopen_window_is_seven_days_from_the_latest_close(): void
    {
        $customer = Customer::factory()->create();

        $vencido = $this->fechado($customer, 8);
        $this->reabrir($customer, $vencido)->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame('fechado', $vencido->refresh()->status);

        // Fechado há 8 dias, mas reaberto e fechado de novo ontem: vale o fechamento mais recente.
        IncidenteEvento::query()->forceCreate([
            'incidente_id' => $vencido->id,
            'user_id' => $vencido->responsavel_id,
            'tipo' => IncidenteEvento::TIPO_FECHADO,
            'created_at' => now()->subDay(),
        ]);
        $this->reabrir($customer, $vencido)->assertOk();
    }

    public function test_legacy_closed_ticket_without_event_uses_concluido_em(): void
    {
        $customer = Customer::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'fechado']);
        $incidente->forceFill(['concluido_em' => now()->subDays(10)])->save();

        $this->reabrir($customer, $incidente)->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_reason_is_required(): void
    {
        $customer = Customer::factory()->create();
        $incidente = $this->fechado($customer);

        $this->reabrir($customer, $incidente, null)->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->reabrir($customer, $incidente, '   ')->assertStatus(422)->assertJsonValidationErrors(['motivo']);
        $this->assertSame('fechado', $incidente->refresh()->status);
    }

    public function test_cannot_reopen_another_customers_ticket(): void
    {
        $dono = Customer::factory()->create();
        $outro = Customer::factory()->create();
        $incidente = $this->fechado($dono);

        $this->reabrir($outro, $incidente)->assertNotFound();
        $this->assertSame('fechado', $incidente->refresh()->status);
    }

    public function test_staff_token_is_rejected(): void
    {
        $incidente = $this->fechado(Customer::factory()->create());
        $staff = User::factory()->create();
        $this->postJson("/api/customer/incidentes/{$incidente->id}/reabrir", ['motivo' => 'x'], [
            'Authorization' => 'Bearer '.$staff->createToken('spa', ['staff'], now()->addHour())->plainTextToken,
        ])->assertUnauthorized();

        $this->assertSame('fechado', $incidente->refresh()->status);
    }
}
