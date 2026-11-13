<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use App\Notifications\ComentarioDoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PortalNotificacaoComentarioTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken];
    }

    private function comentar(Customer $customer, Incidente $incidente, string $texto = 'Ainda está com erro.')
    {
        return $this->postJson("/api/customer/incidentes/{$incidente->id}/comentarios", ['descricao' => $texto], $this->headers($customer));
    }

    public function test_customer_comment_emails_the_assigned_agent_only(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $colega = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'em_andamento', 'grupo_solucao_id' => $grupo->id, 'responsavel_id' => $responsavel->id]);

        $this->comentar($customer, $incidente)->assertCreated();

        Notification::assertSentTo($responsavel, ComentarioDoCliente::class, fn (ComentarioDoCliente $n) => $n->descricao->descricao === 'Ainda está com erro.');
        Notification::assertNotSentTo($colega, ComentarioDoCliente::class);
        Notification::assertCount(1);
    }

    public function test_without_assignee_the_solution_group_members_are_emailed(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $grupo = GrupoSolucao::factory()->create();
        $membros = User::factory()->count(2)->create(['grupo_solucao_id' => $grupo->id]);
        $desativado = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $desativado->delete();
        $deOutroGrupo = User::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'aberto', 'grupo_solucao_id' => $grupo->id, 'responsavel_id' => null]);

        $this->comentar($customer, $incidente)->assertCreated();

        Notification::assertSentTo($membros, ComentarioDoCliente::class);
        Notification::assertNotSentTo([$desativado, $deOutroGrupo], ComentarioDoCliente::class);
    }

    public function test_deactivated_assignee_falls_back_to_the_group(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $membro = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'aberto', 'grupo_solucao_id' => $grupo->id, 'responsavel_id' => $responsavel->id]);
        $responsavel->delete();

        $this->comentar($customer, $incidente)->assertCreated();

        Notification::assertSentTo($membro, ComentarioDoCliente::class);
        Notification::assertNotSentTo($responsavel, ComentarioDoCliente::class);
    }

    public function test_untriaged_ticket_sends_no_email(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'aberto', 'grupo_solucao_id' => null, 'responsavel_id' => null]);

        $this->comentar($customer, $incidente)->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_reopening_emails_the_agent_with_the_reason(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $responsavel = User::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'fechado', 'responsavel_id' => $responsavel->id]);
        $incidente->descricoes()->create(['customer_id' => $customer->id, 'tipo' => 'comentario', 'descricao' => 'Abertura']);
        IncidenteEvento::query()->create(['incidente_id' => $incidente->id, 'user_id' => $responsavel->id, 'tipo' => 'fechado']);

        $this->postJson("/api/customer/incidentes/{$incidente->id}/reabrir", ['motivo' => 'Voltou a falhar.'], $this->headers($customer))->assertOk();

        Notification::assertSentTo($responsavel, ComentarioDoCliente::class, fn (ComentarioDoCliente $n) => str_contains($n->descricao->descricao, 'Voltou a falhar.'));
        Notification::assertCount(1);
    }

    public function test_rejected_comment_or_reopen_sends_nothing(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $responsavel = User::factory()->create();
        $cancelado = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'cancelado', 'responsavel_id' => $responsavel->id]);

        $this->comentar($customer, $cancelado)->assertStatus(422);
        $this->postJson("/api/customer/incidentes/{$cancelado->id}/reabrir", ['motivo' => 'x'], $this->headers($customer))->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_email_content_has_ticket_customer_comment_and_staff_link(): void
    {
        $client = Client::factory()->create(['name' => 'ACME Ltda']);
        $customer = Customer::factory()->create(['name' => 'Carla Cliente', 'client_id' => $client->id]);
        $responsavel = User::factory()->create(['name' => 'Ana Agente', 'email' => 'ana@ex.com']);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'titulo' => 'VPN fora', 'responsavel_id' => $responsavel->id]);
        $descricao = $incidente->descricoes()->create(['customer_id' => $customer->id, 'tipo' => 'comentario', 'descricao' => "Linha 1\n<script>x</script>"]);

        $mail = (new ComentarioDoCliente($descricao))->toMail($responsavel);
        $html = $mail->render();

        $this->assertSame("[#{$incidente->id}] Novo comentário do cliente: VPN fora", $mail->envelope()->subject);
        $this->assertTrue($mail->hasTo('ana@ex.com'));
        $this->assertStringContainsString('Carla Cliente', $html);
        $this->assertStringContainsString('ACME Ltda', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString(rtrim(config('app.frontend_url'), '/')."/incidents/{$incidente->id}", $html);
    }
}
