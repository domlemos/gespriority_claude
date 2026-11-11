<?php

namespace Tests\Feature\Portal;

use App\Models\Customer;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalChamadoDetalheTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
        $token = $this->customer->createToken('spa', ['customer'], now()->addHours(4))->plainTextToken;
        $this->headers = ['Authorization' => "Bearer {$token}"];
    }

    private function chamado(array $attrs = []): Incidente
    {
        return Incidente::factory()->create(['customer_id' => $this->customer->id, 'status' => 'aberto', ...$attrs]);
    }

    private function entrada(Incidente $incidente, string $tipo, ?User $staff, ?Customer $customer, string $texto): IncidenteDescricao
    {
        return IncidenteDescricao::query()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $staff?->id,
            'customer_id' => $customer?->id,
            'tipo' => $tipo,
            'descricao' => $texto,
        ]);
    }

    public function test_list_searches_by_number_or_title_and_filters_by_status(): void
    {
        $impressora = $this->chamado(['titulo' => 'Impressora não imprime']);
        $vpn = $this->chamado(['titulo' => 'VPN cai toda hora', 'status' => 'fechado']);
        Incidente::factory()->create(['titulo' => 'Impressora de outro cliente']);

        $ids = fn ($query) => collect($this->getJson("/api/customer/incidentes?{$query}", $this->headers)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$impressora->id], $ids('busca=impressora'));
        $this->assertSame([$vpn->id], $ids('busca='.$vpn->id));
        $this->assertSame([$vpn->id], $ids('status=fechado'));
        $this->assertSame([], $ids('busca=impressora&status=fechado'));
    }

    public function test_show_returns_own_ticket_with_only_own_comments_opening_and_closing_message(): void
    {
        $incidente = $this->chamado(['status' => 'fechado']);
        $analista = User::factory()->create(['name' => 'Ana Analista']);

        $abertura = $this->entrada($incidente, IncidenteDescricao::TIPO_COMENTARIO, $analista, null, 'Cliente ligou: sistema fora.');
        $interno = $this->entrada($incidente, IncidenteDescricao::TIPO_COMENTARIO, $analista, null, 'Nota interna da equipe.');
        $this->entrada($incidente, IncidenteDescricao::TIPO_ESCALONAMENTO, $analista, null, 'Encaminhado para o grupo X.');
        $doCliente = $this->entrada($incidente, IncidenteDescricao::TIPO_COMENTARIO, null, $this->customer, 'Continua fora.');
        $excluido = $this->entrada($incidente, IncidenteDescricao::TIPO_COMENTARIO, null, $this->customer, 'Apagado.');
        $excluido->delete();
        $encerramento = $this->entrada($incidente, IncidenteDescricao::TIPO_ENCERRAMENTO, $analista, null, 'Normalizado.');

        $response = $this->getJson("/api/customer/incidentes/{$incidente->id}", $this->headers)->assertOk();

        $response->assertJsonPath('data.id', $incidente->id)
            ->assertJsonMissingPath('data.grupo_solucao_id')
            ->assertJsonMissingPath('data.prioridade');
        $this->assertSame(
            [$abertura->id, $doCliente->id, $encerramento->id],
            collect($response->json('data.descricoes'))->pluck('id')->all()
        );
        $this->assertNotContains($interno->descricao, collect($response->json('data.descricoes'))->pluck('descricao'));
        $response->assertJsonPath('data.descricoes.1.autor', ['nome' => $this->customer->name, 'equipe' => false])
            ->assertJsonPath('data.descricoes.2.autor', ['nome' => 'Ana Analista', 'equipe' => true])
            ->assertJsonPath('data.descricoes.2.tipo', 'encerramento');
    }

    public function test_customer_cannot_see_or_comment_on_someone_elses_ticket(): void
    {
        $alheio = Incidente::factory()->create();

        $this->getJson("/api/customer/incidentes/{$alheio->id}", $this->headers)->assertNotFound();
        $this->postJson("/api/customer/incidentes/{$alheio->id}/comentarios", ['descricao' => 'Oi'], $this->headers)
            ->assertNotFound();
    }

    public function test_customer_comments_on_own_open_ticket(): void
    {
        $incidente = $this->chamado();

        $this->postJson("/api/customer/incidentes/{$incidente->id}/comentarios", ['descricao' => 'Mais detalhes.'], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.autor', ['nome' => $this->customer->name, 'equipe' => false]);

        $this->assertDatabaseHas('incidente_descricoes', [
            'incidente_id' => $incidente->id,
            'customer_id' => $this->customer->id,
            'user_id' => null,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Mais detalhes.',
        ]);
    }

    public function test_comment_is_required_and_blocked_on_closed_or_cancelled_tickets(): void
    {
        $this->postJson("/api/customer/incidentes/{$this->chamado()->id}/comentarios", ['descricao' => ''], $this->headers)
            ->assertStatus(422)->assertJsonValidationErrors('descricao');

        foreach (['fechado', 'cancelado'] as $status) {
            $this->postJson("/api/customer/incidentes/{$this->chamado(['status' => $status])->id}/comentarios", ['descricao' => 'Oi'], $this->headers)
                ->assertStatus(422)->assertJsonValidationErrors('descricao');
        }
    }

    public function test_staff_token_cannot_use_portal_detail_routes(): void
    {
        $incidente = $this->chamado();
        $staff = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$staff->createToken('spa', ['staff'])->plainTextToken];

        $this->getJson("/api/customer/incidentes/{$incidente->id}", $headers)->assertUnauthorized();
        $this->postJson("/api/customer/incidentes/{$incidente->id}/comentarios", ['descricao' => 'Oi'], $headers)
            ->assertUnauthorized();
    }
}
