<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteEvento;
use App\Models\Item;
use App\Models\User;
use Faker\Generator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Massa de demonstração de chamados (Incidente/Solicitação) sobre a
 * taxonomia do CategoriasSeeder. **Não** é chamado pelo DatabaseSeeder —
 * só pelo comando `php artisan chamados:popular` (ver routes/console.php e
 * BACKEND_SPECS.md §3.7), porque não é idempotente: cada execução cria mais
 * `$quantidade` chamados.
 *
 * Distribuição (aproximada): ~70% incidentes, ~30% solicitações. Entre os
 * incidentes, uma parte chega pelo portal ainda sem classificação (fila de
 * triagem); solicitações são sempre abertas pelo staff em nome de alguém da
 * empresa interna (o portal só abre incidente). Datas espalhadas pelos
 * últimos 60 dias, status variados, encaminhamento e eventos de conclusão
 * coerentes com o status — pra alimentar dashboard e relatórios.
 */
class ChamadosDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public int $quantidade = 100;

    private const ORIGENS_STAFF = ['email', 'telefone', 'chat', 'presencial', 'monitoramento'];

    private const STATUS_PESOS = [
        'aberto' => 20, 'em_andamento' => 20, 'pendente' => 10,
        'resolvido' => 20, 'fechado' => 25, 'cancelado' => 5,
    ];

    /** Título de incidente por nome de item da taxonomia (`%s` = "Subcategoria (Categoria)"). */
    private const TITULOS_INCIDENTE = [
        'Divergência' => 'Divergência de dados em %s',
        'Falha' => 'Falha ao gerar %s',
        'Critérios' => 'Critérios não aplicados em %s',
        'Aprovação' => 'Aprovação travada em %s',
        'Cálculo' => 'Cálculo incorreto em %s',
        'Erro' => 'Erro ao consultar %s',
        'Não processa' => '%s não processa a requisição',
        'Chassi' => 'Chassi não retornado em %s',
        'Vídeo não carrega' => 'Vídeo não carrega em %s',
        'Lentidão' => 'Lentidão em %s',
        'Captura' => 'Falha de captura em %s',
        'Preventivo' => 'Manutenção preventiva pendente em %s',
        'Corretivo' => 'Manutenção corretiva necessária em %s',
    ];

    /** Demandas pontuais de cliente interno — ver "Tipo do chamado" no BACKEND_SPECS.md §3.1. */
    private const TITULOS_SOLICITACAO = [
        'Adequar infraestrutura de %s para o novo volume',
        'Criar script de reprocessamento para %s',
        'Contatar cliente para informar manutenção em %s',
        'Liberar acesso de homologação a %s',
        'Gerar extração de dados de %s para auditoria',
        'Ajustar parâmetro de configuração em %s',
        'Atualizar documentação de %s',
        'Agendar janela de manutenção para %s',
    ];

    private Generator $faker;

    public function run(): void
    {
        // Seed fixa: a mesma execução gera a mesma massa (útil pra comparar
        // dashboards/relatórios entre ambientes).
        $this->faker = fake('pt_BR');
        $this->faker->seed(20261109);

        $itens = Item::query()
            ->where('ativo', true)
            ->whereNotNull('prioridade_padrao')
            ->with('subcategoria.categoria')
            ->get();
        $staff = User::query()->get();
        $grupos = GrupoSolucao::query()->where('ativo', true)->get();
        $clientesExternos = Customer::query()->whereHas('client', fn ($q) => $q->where('name', '!=', 'Equipe Interna'))->get();

        if ($itens->isEmpty() || $staff->isEmpty() || $grupos->isEmpty() || $clientesExternos->isEmpty()) {
            throw new RuntimeException('Rode `php artisan db:seed` antes: faltam itens com prioridade padrão, usuários, grupos ou clientes.');
        }

        $clientesInternos = $this->clientesInternos();

        for ($i = 0; $i < $this->quantidade; $i++) {
            $tipo = $this->faker->boolean(70) ? 'incidente' : 'solicitacao';
            $doPortal = $tipo === 'incidente' && $this->faker->boolean(20);

            $doPortal
                ? $this->criarDoPortal($clientesExternos->random())
                : $this->criarPeloStaff(
                    $tipo,
                    $tipo === 'incidente' ? $clientesExternos->random() : $clientesInternos->random(),
                    $itens->random(),
                    $staff,
                    $grupos,
                );
        }
    }

    /**
     * "A própria empresa é cadastrada como um customer" — Client interno com
     * alguns funcionários, solicitantes das solicitações. Idempotente.
     */
    private function clientesInternos(): Collection
    {
        $empresa = Client::query()->firstOrCreate(['name' => 'Equipe Interna']);

        return collect([
            ['Ana Produtos', 'ana.produtos@interno.example.com'],
            ['Bruno Comercial', 'bruno.comercial@interno.example.com'],
            ['Carla Sustentação', 'carla.sustentacao@interno.example.com'],
            ['Diego Financeiro', 'diego.financeiro@interno.example.com'],
        ])->map(fn (array $pessoa) => Customer::query()->firstOrCreate(
            ['email' => $pessoa[1]],
            ['name' => $pessoa[0], 'client_id' => $empresa->id, 'password' => Hash::make('password')],
        ));
    }

    /** Incidente aberto pelo cliente no portal, ainda na fila de triagem (sem classificação). */
    private function criarDoPortal(Customer $customer): void
    {
        $criadoEm = now()->subMinutes($this->faker->numberBetween(10, 60 * 24 * 3));

        $incidente = Incidente::query()->create([
            'customer_id' => $customer->id,
            'titulo' => $this->faker->randomElement([
                'Sistema não carrega desde cedo',
                'Consulta retornando erro inesperado',
                'Relatório veio com dados faltando',
                'Não consigo acessar minha conta',
                'Lentidão para gerar o dossiê',
            ]),
            'tipo' => 'incidente',
            'origem' => 'portal',
            'status' => 'aberto',
        ]);
        $incidente->forceFill([
            'criado_por_customer_id' => $customer->id,
            'created_at' => $criadoEm,
            'updated_at' => $criadoEm,
        ])->save();

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente->id,
            'customer_id' => $customer->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => $this->faker->paragraph(2),
        ])->forceFill(['created_at' => $criadoEm, 'updated_at' => $criadoEm])->save();
    }

    private function criarPeloStaff(string $tipo, Customer $customer, Item $item, Collection $staff, Collection $grupos): void
    {
        $status = $this->faker->randomElement($this->expandirPesos(self::STATUS_PESOS));
        $criadoEm = now()->subMinutes($this->faker->numberBetween(30, 60 * 24 * 60));
        $criador = $staff->random();
        $grupo = $status === 'aberto' && $this->faker->boolean(40) ? null : $grupos->random();
        $responsavel = $grupo && $status !== 'aberto'
            ? ($staff->firstWhere('grupo_solucao_id', $grupo->id) ?? $staff->random())
            : null;
        $alvo = "{$item->subcategoria->nome} ({$item->subcategoria->categoria->nome})";

        $incidente = Incidente::query()->create([
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'grupo_solucao_id' => $grupo?->id,
            'responsavel_id' => $responsavel?->id,
            'titulo' => $tipo === 'incidente'
                ? sprintf(self::TITULOS_INCIDENTE[$item->nome] ?? 'Problema em %s', $alvo)
                : sprintf($this->faker->randomElement(self::TITULOS_SOLICITACAO), $alvo),
            // Mesma regra do IncidenteController: prioridade vem do item.
            'prioridade' => $item->prioridade_padrao,
            'tipo' => $tipo,
            'origem' => $this->faker->randomElement(self::ORIGENS_STAFF),
            'status' => $status,
        ]);
        $incidente->forceFill([
            'criado_por_id' => $criador->id,
            'created_at' => $criadoEm,
            'updated_at' => $criadoEm,
        ])->save();
        $this->calcularPrazosSla($incidente);

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $criador->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => $this->faker->paragraph(2),
        ])->forceFill(['created_at' => $criadoEm, 'updated_at' => $criadoEm])->save();

        $this->registrarEventos($incidente, $criador, $grupo, $responsavel, $status, $criadoEm);
    }

    /** Encaminhamentos e conclusão coerentes com o status — mesma convenção do IncidentesSeeder. */
    private function registrarEventos(Incidente $incidente, User $criador, ?GrupoSolucao $grupo, ?User $responsavel, string $status, $criadoEm): void
    {
        if ($grupo) {
            $this->evento($incidente, $criador, IncidenteEvento::TIPO_ENCAMINHADO_GRUPO, $criadoEm, GrupoSolucao::class, $grupo->id);
        }

        if ($responsavel) {
            $this->evento($incidente, $criador, IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL, $criadoEm, User::class, $responsavel->id);
        }

        if ($status === 'aberto') {
            return;
        }

        // Saiu de 'aberto' — já houve primeira resposta.
        $respondidoEm = $criadoEm->copy()->addMinutes($this->faker->numberBetween(5, 180));
        $incidente->forceFill(['respondido_em' => $respondidoEm])->save();

        if (! in_array($status, Incidente::STATUS_CONCLUIDOS, true)) {
            return;
        }

        $concluidoEm = $respondidoEm->copy()->addMinutes($this->faker->numberBetween(30, 60 * 72));
        $incidente->forceFill(['concluido_em' => min($concluidoEm, now())])->save();

        if ($status === 'cancelado') {
            return;
        }

        $autor = $responsavel ?? $criador;
        $this->evento($incidente, $autor, IncidenteEvento::TIPO_RESOLVIDO, $incidente->concluido_em);

        if ($status === 'fechado') {
            $this->evento($incidente, $autor, IncidenteEvento::TIPO_FECHADO, $incidente->concluido_em);
        }
    }

    private function evento(Incidente $incidente, User $autor, string $tipo, $quando, ?string $alvoType = null, ?int $alvoId = null): void
    {
        IncidenteEvento::query()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor->id,
            'tipo' => $tipo,
            'alvo_type' => $alvoType,
            'alvo_id' => $alvoId,
        ])->forceFill(['created_at' => $quando])->save();
    }

    /** @param  array<string, int>  $pesos */
    private function expandirPesos(array $pesos): array
    {
        return collect($pesos)->flatMap(fn (int $peso, string $valor) => array_fill(0, $peso, $valor))->all();
    }

    /**
     * Mesma lógica de IncidenteController::calcularPrazosSla() — duplicada
     * de propósito, como no IncidentesSeeder (sem camada de serviço, ver
     * BACKEND_SPECS.md §3.5).
     */
    private function calcularPrazosSla(Incidente $incidente): void
    {
        $politica = $incidente->loadMissing('customer.client')
            ->customer->client?->resolvedSlaFor($incidente->prioridade);

        $incidente->prazo_resposta = $politica
            ? $incidente->created_at->copy()->addMinutes($politica->tempo_resposta_minutos)
            : null;
        $incidente->prazo_resolucao = $politica
            ? $incidente->created_at->copy()->addMinutes($politica->tempo_resolucao_minutos)
            : null;
        $incidente->save();
    }
}
