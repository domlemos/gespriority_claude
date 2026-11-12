<?php

namespace Tests\Feature\Seeders;

use App\Models\Client;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteEvento;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChamadosDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_default_seed_does_not_create_demo_tickets(): void
    {
        // O seeder de demo nunca roda no db:seed: nem a empresa interna nem
        // chamados abertos pelo portal (só ele cria esses dois).
        $this->assertTrue(Client::query()->where('name', 'Equipe Interna')->doesntExist());
        $this->assertTrue(Incidente::query()->whereNotNull('criado_por_customer_id')->doesntExist());
    }

    public function test_command_creates_100_tickets_mixing_incidente_and_solicitacao(): void
    {
        $ultimoId = Incidente::query()->max('id');

        $this->artisan('chamados:popular')->assertSuccessful();

        $novos = Incidente::query()->with('item')->where('id', '>', $ultimoId)->get();
        $this->assertCount(100, $novos);
        $this->assertGreaterThan(0, $novos->where('tipo', 'solicitacao')->count());
        $this->assertGreaterThan(0, $novos->where('tipo', 'incidente')->count());

        // Toda solicitação é de staff (o portal só abre incidente).
        $this->assertTrue($novos->where('tipo', 'solicitacao')->every(fn ($i) => $i->criado_por_id !== null && $i->origem !== 'portal'));

        // Classificados usam a taxonomia do CategoriasSeeder e têm prioridade do item.
        $classificados = $novos->whereNotNull('item_id');
        $this->assertGreaterThan(0, $classificados->count());
        $this->assertTrue($classificados->every(fn ($i) => $i->prioridade === $i->item->prioridade_padrao));

        // Abertos pelo portal ainda não triados: sem item/prioridade, autor cliente.
        $doPortal = $novos->whereNotNull('criado_por_customer_id');
        $this->assertGreaterThan(0, $doPortal->count());
        $this->assertTrue($doPortal->every(fn ($i) => $i->tipo === 'incidente' && $i->criado_por_id === null));

        // Todo chamado tem a descrição de abertura no feed.
        $this->assertSame(100, IncidenteDescricao::query()->whereIn('incidente_id', $novos->pluck('id'))
            ->distinct()->count('incidente_id'));
    }

    /** Todo chamado cancelado tem o evento `cancelado` (o relatório de indicadores conta por ele). */
    private function assertCanceladosComEvento(): void
    {
        $semEvento = Incidente::query()
            ->where('status', 'cancelado')
            ->whereNotIn('id', IncidenteEvento::query()->where('tipo', IncidenteEvento::TIPO_CANCELADO)->select('incidente_id'))
            ->pluck('id');

        $this->assertSame([], $semEvento->all());
    }

    public function test_default_seed_cancelled_tickets_have_cancelado_event(): void
    {
        $this->assertGreaterThan(0, Incidente::query()->where('status', 'cancelado')->count());
        $this->assertCanceladosComEvento();
    }

    public function test_demo_cancelled_tickets_have_cancelado_event(): void
    {
        $this->artisan('chamados:popular')->assertSuccessful();

        $this->assertCanceladosComEvento();
    }

    public function test_quantidade_option(): void
    {
        $antes = Incidente::query()->count();

        $this->artisan('chamados:popular', ['--quantidade' => 7])->assertSuccessful();

        $this->assertSame($antes + 7, Incidente::query()->count());
    }

    public function test_refuses_in_production_without_force(): void
    {
        $this->app['env'] = 'production';
        $antes = Incidente::query()->count();

        $this->artisan('chamados:popular')
            ->expectsConfirmation('Deseja realmente executar este comando?', 'no')
            ->assertFailed();

        $this->assertSame($antes, Incidente::query()->count());
    }
}
