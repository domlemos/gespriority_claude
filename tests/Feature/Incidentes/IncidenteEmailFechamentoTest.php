<?php

namespace Tests\Feature\Incidentes;

use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ChamadoFechado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncidenteEmailFechamentoTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin: enxerga todos os grupos e edita todos os campos.
        $role = Role::factory()->create(['slug' => 'admin']);
        foreach (['tickets.view', 'tickets.manage', 'tickets.edit_all'] as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $staff = User::factory()->create();
        $staff->roles()->attach($role);
        $this->token = $staff->createToken('spa', ['staff'], now()->addHour())->plainTextToken;
    }

    private function atualizar(Incidente $incidente, array $payload)
    {
        return $this->putJson("/api/incidentes/{$incidente->id}", $payload, ['Authorization' => "Bearer {$this->token}"]);
    }

    public function test_closing_emails_the_customer_with_the_closing_message(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'resolvido']);

        $this->atualizar($incidente, ['status' => 'fechado', 'mensagem_encerramento' => 'Trocamos o cabo.'])->assertOk();

        Notification::assertSentTo($customer, ChamadoFechado::class, fn (ChamadoFechado $n) => $n->encerramento->descricao === 'Trocamos o cabo.'
            && $n->encerramento->incidente_id === $incidente->id);
        Notification::assertCount(1);
    }

    public function test_no_email_when_not_closing(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();

        foreach (['em_andamento', 'resolvido', 'cancelado', 'aguardando_externo'] as $status) {
            $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'aberto']);
            $this->atualizar($incidente, ['status' => $status])->assertOk();
        }

        // Já fechado: reenviar 'fechado' não é um novo fechamento.
        $fechado = Incidente::factory()->create(['customer_id' => $customer->id, 'status' => 'fechado']);
        $this->atualizar($fechado, ['status' => 'fechado', 'titulo' => 'Outro título'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_rejected_close_without_message_sends_nothing(): void
    {
        Notification::fake();
        $incidente = Incidente::factory()->create(['status' => 'em_andamento']);

        $this->atualizar($incidente, ['status' => 'fechado'])->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_group_change_in_the_same_request_cancels_the_close_and_the_email(): void
    {
        Notification::fake();
        $incidente = Incidente::factory()->create(['status' => 'em_andamento', 'grupo_solucao_id' => GrupoSolucao::factory()->create()->id]);

        $this->atualizar($incidente, [
            'status' => 'fechado',
            'mensagem_encerramento' => 'x',
            'grupo_solucao_id' => GrupoSolucao::factory()->create()->id,
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_customer_changed_in_the_same_request_receives_the_email(): void
    {
        Notification::fake();
        $antigo = Customer::factory()->create();
        $novo = Customer::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $antigo->id, 'status' => 'resolvido']);
        $incidente->load('customer');

        $this->atualizar($incidente, ['status' => 'fechado', 'mensagem_encerramento' => 'ok', 'customer_id' => $novo->id])->assertOk();

        Notification::assertSentTo($novo, ChamadoFechado::class);
        Notification::assertNotSentTo($antigo, ChamadoFechado::class);
    }

    public function test_email_content(): void
    {
        $customer = Customer::factory()->create(['name' => 'Carla Cliente', 'email' => 'carla@ex.com']);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'titulo' => 'VPN fora']);
        $encerramento = $incidente->descricoes()->create([
            'user_id' => User::factory()->create()->id,
            'tipo' => 'encerramento',
            'descricao' => "Ajustamos a VPN.\n<b>ok</b>",
        ]);

        $mail = (new ChamadoFechado($encerramento))->toMail($customer);
        $html = $mail->render();

        $this->assertSame("[#{$incidente->id}] Seu chamado foi fechado: VPN fora", $mail->envelope()->subject);
        $this->assertTrue($mail->hasTo('carla@ex.com'));
        $this->assertStringContainsString('Carla Cliente', $html);
        $this->assertStringContainsString('&lt;b&gt;ok&lt;/b&gt;', $html);
        $this->assertStringContainsString('em até 7 dias', $html);
        $this->assertStringContainsString(rtrim(config('app.frontend_url'), '/')."/portal/incidentes/{$incidente->id}", $html);
    }
}
