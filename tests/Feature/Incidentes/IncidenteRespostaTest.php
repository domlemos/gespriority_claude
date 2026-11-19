<?php

namespace Tests\Feature\Incidentes;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\RespostaAoCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Resposta ao cliente (POST /incidentes/{id}/respostas): entrada 'resposta'
 * no feed, visível no portal, imutável. Ver BACKEND_SPECS.md §3.4.7.1.
 */
class IncidenteRespostaTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = $this->staffCom('admin', ['tickets.view', 'tickets.manage', 'tickets.edit_all']);
        $this->token = $this->tokenDe($this->staff);
    }

    private function staffCom(string $papel, array $slugs, array $attrs = []): User
    {
        $role = Role::factory()->create(['slug' => $papel]);
        foreach ($slugs as $slug) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        }
        $user = User::factory()->create($attrs);
        $user->roles()->attach($role);

        return $user;
    }

    private function tokenDe(User $user): string
    {
        return $user->createToken('spa', ['staff'], now()->addHour())->plainTextToken;
    }

    private function responder(Incidente $incidente, array $payload, ?string $token = null)
    {
        return $this->postJson("/api/incidentes/{$incidente->id}/respostas", $payload, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_staff_cria_resposta(): void
    {
        $incidente = Incidente::factory()->create(['status' => 'em_andamento']);
        $outro = User::factory()->create();

        $this->responder($incidente, ['descricao' => 'Já estamos verificando.', 'tipo' => 'comentario', 'user_id' => $outro->id])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'resposta')
            ->assertJsonPath('data.descricao', 'Já estamos verificando.')
            ->assertJsonPath('data.user.id', $this->staff->id);

        $this->assertDatabaseHas('incidente_descricoes', [
            'incidente_id' => $incidente->id,
            'tipo' => IncidenteDescricao::TIPO_RESPOSTA,
            'user_id' => $this->staff->id,
            'customer_id' => null,
        ]);
    }

    public function test_resposta_nao_muda_status(): void
    {
        foreach (['aberto', 'pendente', 'fechado', 'cancelado'] as $status) {
            $incidente = Incidente::factory()->create(['status' => $status]);

            $this->responder($incidente, ['descricao' => 'Oi'])->assertCreated();

            $this->assertSame($status, $incidente->fresh()->status);
        }
    }

    public function test_validacao(): void
    {
        $incidente = Incidente::factory()->create();

        foreach ([[], ['descricao' => '   '], ['descricao' => str_repeat('a', 5001)]] as $payload) {
            $this->responder($incidente, $payload)->assertStatus(422)->assertJsonValidationErrors('descricao');
        }

        $this->responder($incidente, ['descricao' => str_repeat('a', 5000)])->assertCreated();
        $this->assertSame(1, IncidenteDescricao::query()->where('tipo', 'resposta')->count());
    }

    public function test_sem_tickets_manage_e_403(): void
    {
        $leitor = $this->staffCom('leitor', ['tickets.view']);
        $incidente = Incidente::factory()->create();

        $this->responder($incidente, ['descricao' => 'Oi'], $this->tokenDe($leitor))->assertForbidden();

        $this->assertSame(0, IncidenteDescricao::query()->where('tipo', 'resposta')->count());
    }

    public function test_chamado_de_grupo_nao_visivel_e_403(): void
    {
        $meuGrupo = GrupoSolucao::factory()->create();
        $outroGrupo = GrupoSolucao::factory()->create();
        $agente = $this->staffCom('agente', ['tickets.view', 'tickets.manage'], ['grupo_solucao_id' => $meuGrupo->id]);
        $incidente = Incidente::factory()->create(['grupo_solucao_id' => $outroGrupo->id]);

        $this->responder($incidente, ['descricao' => 'Oi'], $this->tokenDe($agente))->assertForbidden();

        $this->assertSame(0, IncidenteDescricao::query()->where('tipo', 'resposta')->count());
    }

    public function test_resposta_e_imutavel(): void
    {
        $incidente = Incidente::factory()->create();
        $id = $this->responder($incidente, ['descricao' => 'Oi'])->assertCreated()->json('data.id');
        $headers = ['Authorization' => "Bearer {$this->token}"];

        $this->putJson("/api/incidentes/{$incidente->id}/descricoes/{$id}", ['descricao' => 'Outro'], $headers)->assertForbidden();
        $this->deleteJson("/api/incidentes/{$incidente->id}/descricoes/{$id}", [], $headers)->assertForbidden();

        $this->assertSame('Oi', IncidenteDescricao::query()->find($id)->descricao);
        $this->assertNull(IncidenteDescricao::query()->find($id)->deleted_at);
    }

    public function test_portal_mostra_resposta_ao_dono(): void
    {
        $customer = Customer::factory()->create();
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id]);
        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente->id, 'customer_id' => $customer->id, 'tipo' => 'comentario', 'descricao' => 'Abertura',
        ]);
        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente->id, 'user_id' => $this->staff->id, 'tipo' => 'comentario', 'descricao' => 'Nota interna',
        ]);
        $this->responder($incidente, ['descricao' => 'Resposta ao cliente'])->assertCreated();

        $tokenCliente = $customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;
        $descricoes = collect($this->getJson("/api/customer/incidentes/{$incidente->id}", ['Authorization' => "Bearer {$tokenCliente}"])
            ->assertOk()->json('data.descricoes'));

        $this->assertSame(['Abertura', 'Resposta ao cliente'], $descricoes->pluck('descricao')->all());
        $resposta = $descricoes->firstWhere('descricao', 'Resposta ao cliente');
        $this->assertSame('resposta', $resposta['tipo']);
        $this->assertTrue($resposta['autor']['equipe']);

        // Outro cliente não vê o chamado (nem a resposta). forgetGuards():
        // senão o guard reaproveita o cliente da requisição anterior.
        $this->app['auth']->forgetGuards();
        $alheio = Customer::factory()->create()->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;
        $this->getJson("/api/customer/incidentes/{$incidente->id}", ['Authorization' => "Bearer {$alheio}"])->assertNotFound();
    }

    public function test_resposta_entra_no_log_de_acoes(): void
    {
        $incidente = Incidente::factory()->create();

        $id = $this->responder($incidente, ['descricao' => 'Texto da resposta'])->assertCreated()->json('data.id');

        $log = AuditLog::query()->where('entidade_type', 'incidente_descricao')->where('entidade_id', $id)->sole();
        $this->assertSame('criado', $log->acao);
        $this->assertStringNotContainsString('Texto da resposta', json_encode($log->alteracoes));
    }

    public function test_resposta_envia_email_ao_cliente(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();
        $grupo = GrupoSolucao::factory()->create();
        $responsavel = User::factory()->create(['grupo_solucao_id' => $grupo->id]);
        $incidente = Incidente::factory()->create([
            'customer_id' => $customer->id, 'grupo_solucao_id' => $grupo->id, 'responsavel_id' => $responsavel->id,
        ]);

        $this->responder($incidente, ['descricao' => 'Já estamos verificando.'])->assertCreated();

        Notification::assertSentTo($customer, RespostaAoCliente::class, fn (RespostaAoCliente $n) => $n->resposta->descricao === 'Já estamos verificando.'
            && $n->resposta->incidente_id === $incidente->id);
        Notification::assertCount(1);
    }

    public function test_resposta_recusada_nao_envia(): void
    {
        Notification::fake();
        $incidente = Incidente::factory()->create();

        $this->responder($incidente, ['descricao' => '   '])->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_conteudo_do_email(): void
    {
        config(['app.frontend_url' => 'https://app.test/']);
        $customer = Customer::factory()->create(['name' => 'Bia', 'email' => 'bia@cliente.test']);
        $incidente = Incidente::factory()->create(['customer_id' => $customer->id, 'titulo' => 'Rede caiu']);
        $agente = User::factory()->create(['name' => 'Carlos Agente']);
        $resposta = IncidenteDescricao::query()->create([
            'incidente_id' => $incidente->id, 'user_id' => $agente->id, 'tipo' => 'resposta', 'descricao' => '<script>alert(1)</script> Trocamos o cabo.',
        ]);

        $mail = (new RespostaAoCliente($resposta))->toMail($customer);
        $html = $mail->render();

        $this->assertTrue($mail->hasTo('bia@cliente.test'));
        $this->assertSame("[#{$incidente->id}] Nova resposta ao seu chamado: Rede caiu", $mail->envelope()->subject);
        $this->assertStringContainsString('Carlos Agente', $html);
        $this->assertStringContainsString('Trocamos o cabo.', $html);
        $this->assertStringContainsString("https://app.test/portal/incidentes/{$incidente->id}", $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }
}
