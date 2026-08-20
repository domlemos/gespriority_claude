<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteResolucao;
use App\Models\Item;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class IncidentesSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Não idempotente por design (Incidente não tem uma chave natural pra
        // usar em updateOrCreate, mesmo raciocínio dos customers aleatórios
        // no DatabaseSeeder) — só roda na primeira vez.
        if (Incidente::query()->exists()) {
            return;
        }

        $customer = Customer::query()->where('email', 'cliente@example.com')->first();
        $admin = User::query()->where('email', 'admin@example.com')->first();
        $supervisor = User::query()->where('email', 'supervisor@example.com')->first();
        $agente = User::query()->where('email', 'agente@example.com')->first();

        if (! $customer || ! $admin || ! $supervisor || ! $agente) {
            return;
        }

        $itemImpressora = Item::query()->where('nome', 'Sem toner')->first();
        $itemInternet = Item::query()->where('nome', 'Sem conexão')->first();
        $grupoN1 = GrupoSolucao::query()->where('nome', 'Suporte N1')->first();

        // Incidente já triado e em andamento — demonstra o feed completo:
        // abertura -> escalonamento -> acompanhamento do agente.
        $incidente1 = Incidente::query()->create([
            'customer_id' => $customer->id,
            'item_id' => $itemImpressora?->id,
            'grupo_solucao_id' => $grupoN1?->id,
            'responsavel_id' => $agente->id,
            'titulo' => 'Impressora do 3º andar sem toner',
            'prioridade' => 'media',
            'origem' => 'portal',
            'status' => 'em_andamento',
        ]);
        $this->calcularPrazosSla($incidente1);
        // "em_andamento" pressupõe que já saiu de 'aberto' — mesma regra de
        // IncidenteController::registrarTransicaoDeStatus().
        $incidente1->forceFill(['respondido_em' => $incidente1->created_at])->save();

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente1->id,
            'user_id' => $admin->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Impressora HP do 3º andar parou de imprimir, indicando toner vazio.',
        ]);

        if ($grupoN1) {
            IncidenteDescricao::query()->create([
                'incidente_id' => $incidente1->id,
                'user_id' => $admin->id,
                'tipo' => IncidenteDescricao::TIPO_ESCALONAMENTO,
                'descricao' => "Encaminhado para o grupo '{$grupoN1->nome}'.",
            ]);
        }

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente1->id,
            'user_id' => $agente->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Toner realmente vazio, providenciando a troca.',
        ]);

        // Incidente recém-aberto, ainda sem triagem — feed com só a abertura.
        $incidente2 = Incidente::query()->create([
            'customer_id' => $customer->id,
            'item_id' => $itemInternet?->id,
            'grupo_solucao_id' => null,
            'responsavel_id' => null,
            'titulo' => 'Sem acesso à internet no setor financeiro',
            'prioridade' => 'urgente',
            'origem' => 'telefone',
            'status' => 'aberto',
        ]);
        $this->calcularPrazosSla($incidente2);

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente2->id,
            'user_id' => $admin->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Nenhum computador do setor financeiro consegue acessar sites externos desde hoje de manhã.',
        ]);

        $this->criarIncidentesDemoRelatorios($customer, $admin, $supervisor, $agente);
    }

    /**
     * Massa de incidentes fechados espalhada por todas as dimensões que
     * `RelatorioController` sabe agrupar (status_sla, responsavel,
     * grupo_solucao, categoria/subcategoria/item) e por várias datas —
     * sem isso, um `migrate:fresh --seed` não dava nenhum dado pra ver nos
     * relatórios (só os 2 incidentes acima, nenhum fechado). Sem feed
     * (`IncidenteDescricao`) de propósito, pra não inflar o seeder — o foco
     * aqui é volume/variedade pros relatórios, o feed completo já está
     * demonstrado em `$incidente1` acima.
     */
    private function criarIncidentesDemoRelatorios(Customer $customerPrincipal, User $admin, User $supervisor, User $agente): void
    {
        $grupoN1 = GrupoSolucao::query()->where('nome', 'Suporte N1')->first();
        $grupoN2 = GrupoSolucao::query()->where('nome', 'Suporte N2')->first();
        $grupoRedes = GrupoSolucao::query()->where('nome', 'Redes')->first();

        $itemNaoLiga = Item::query()->where('nome', 'Não liga')->first();
        $itemMouse = Item::query()->where('nome', 'Mouse não funciona')->first();
        $itemLentidaoSo = Categoria::query()->where('nome', 'Software')->first()
            ?->subcategorias()->where('nome', 'Sistema Operacional')->first()
            ?->itens()->where('nome', 'Lentidão')->first();
        $itemLicencaExpirada = Item::query()->where('nome', 'Expirada')->first();
        $itemAppNaoAbre = Item::query()->where('nome', 'Não abre')->first();
        $itemVpnQueda = Item::query()->where('nome', 'Queda de conexão')->first();
        $itemWifiFraco = Item::query()->where('nome', 'Sinal fraco')->first();
        $itemContaBloqueada = Item::query()->where('nome', 'Desbloqueio de conta')->first();
        $itemAcessoNegado = Item::query()->where('nome', 'Acesso negado')->first();

        // Segundo cliente — só pra dar sentido de verdade ao filtro
        // `client_id` dos relatórios (com um cliente só, o filtro nunca
        // muda o resultado).
        $segundoClient = Client::query()->firstOrCreate(['name' => 'TechCorp Soluções']);
        $segundoCustomer = Customer::query()->updateOrCreate(
            ['email' => 'financeiro@techcorp.example.com'],
            ['name' => 'Financeiro TechCorp', 'client_id' => $segundoClient->id, 'password' => Hash::make('password')]
        );

        // {titulo, item, grupo, responsavel, prioridade, origem, status, dias atrás da abertura, horas até a conclusão (null = sem SLA), customer}
        $incidentes = [
            ['Notebook não liga após queda de energia', $itemNaoLiga, $grupoN1, $agente, 'alta', 'telefone', 'fechado', 10, 7, $customerPrincipal],
            ['Sistema travando toda hora', $itemLentidaoSo, $grupoN2, $supervisor, 'media', 'portal', 'resolvido', 8, 30, $customerPrincipal],
            ['VPN caindo direto', $itemVpnQueda, $grupoRedes, $agente, 'urgente', 'email', 'fechado', 5, 3, $customerPrincipal],
            ['Conta bloqueada após tentativas', $itemContaBloqueada, $grupoN1, $admin, 'baixa', 'chat', 'cancelado', 3, 1, $customerPrincipal],
            ['Licença do sistema expirada', $itemLicencaExpirada, $grupoN2, null, 'alta', 'portal', 'fechado', 15, 20, $customerPrincipal],
            ['Wi-Fi com sinal fraco no 2º andar', $itemWifiFraco, $grupoRedes, $agente, 'baixa', 'presencial', 'resolvido', 20, 40, $customerPrincipal],
            ['Acesso negado ao sistema financeiro', $itemAcessoNegado, null, $supervisor, 'media', 'portal', 'fechado', 2, 10, $segundoCustomer],
            ['Mouse sem funcionar', $itemMouse, $grupoN1, $agente, 'baixa', 'portal', 'fechado', 1, null, $customerPrincipal],
            ['Erro ao abrir aplicativo', $itemAppNaoAbre, $grupoN2, $admin, 'media', 'monitoramento', 'fechado', 25, 30, $segundoCustomer],
            ['Chamado sem classificação de item', null, $grupoN1, $agente, 'media', 'telefone', 'fechado', 4, 10, $customerPrincipal],
        ];

        foreach ($incidentes as $index => [$titulo, $item, $grupo, $responsavel, $prioridade, $origem, $status, $diasAtras, $horasAteConcluir, $customer]) {
            $incidente = $this->criarIncidenteFechado(
                customer: $customer,
                item: $item,
                grupo: $grupo,
                responsavel: $responsavel,
                titulo: $titulo,
                prioridade: $prioridade,
                origem: $origem,
                status: $status,
                criadoEm: now()->subDays($diasAtras),
                horasAteConcluir: $horasAteConcluir,
            );

            // 'cancelado' nunca passou por 'resolvido' — sem evento. Pro
            // primeiro incidente ("Notebook não liga..."), simula o
            // cenário de reabertura que motivou essa tabela: resolvido pelo
            // agente, reaberto, resolvido de novo pelo supervisor — as
            // DUAS resoluções ficam registradas, não só a mais recente.
            if ($status === 'cancelado') {
                continue;
            }

            if ($index === 0) {
                IncidenteResolucao::query()->create(['incidente_id' => $incidente->id, 'user_id' => $agente->id]);
                IncidenteResolucao::query()->create(['incidente_id' => $incidente->id, 'user_id' => $supervisor->id]);

                continue;
            }

            IncidenteResolucao::query()->create([
                'incidente_id' => $incidente->id,
                'user_id' => ($responsavel ?? $admin)->id,
            ]);
        }
    }

    /**
     * Cria um incidente já concluído, com `created_at` retroativo (pra
     * espalhar os relatórios por várias datas) e `concluido_em` calculado a
     * partir dele — `$horasAteConcluir === null` simula "sem política de
     * SLA aplicável" (`prazo_resolucao` fica `null`, força o bucket
     * `sem_sla` no relatório por status_sla).
     */
    private function criarIncidenteFechado(
        Customer $customer,
        ?Item $item,
        ?GrupoSolucao $grupo,
        ?User $responsavel,
        string $titulo,
        string $prioridade,
        string $origem,
        string $status,
        Carbon $criadoEm,
        ?int $horasAteConcluir,
    ): Incidente {
        $incidente = Incidente::query()->create([
            'customer_id' => $customer->id,
            'item_id' => $item?->id,
            'grupo_solucao_id' => $grupo?->id,
            'responsavel_id' => $responsavel?->id,
            'titulo' => $titulo,
            'prioridade' => $prioridade,
            'origem' => $origem,
            'status' => $status,
        ]);

        $incidente->forceFill(['created_at' => $criadoEm, 'updated_at' => $criadoEm])->save();

        if ($horasAteConcluir === null) {
            // "Sem SLA aplicável" — normalmente só aconteceria se a política
            // fosse removida depois da abertura; aqui simulado direto pra
            // exercitar o bucket 'sem_sla' do relatório sem depender de
            // desativar uma PoliticaSla de verdade.
            $incidente->forceFill(['prazo_resposta' => null, 'prazo_resolucao' => null])->save();
        } else {
            $this->calcularPrazosSla($incidente);
        }

        $incidente->forceFill([
            'respondido_em' => $criadoEm->copy()->addMinutes(30),
            'concluido_em' => $criadoEm->copy()->addHours($horasAteConcluir ?? 1),
        ])->save();

        return $incidente;
    }

    /**
     * Mesma lógica de IncidenteController::calcularPrazosSla() — duplicada
     * aqui de propósito (sem camada de serviço, ver BACKEND_SPECS.md §3.5),
     * já que o seeder não passa pela camada HTTP.
     */
    private function calcularPrazosSla(Incidente $incidente): void
    {
        $politica = $incidente->loadMissing('customer.client')
            ->customer->client?->resolvedSlaFor($incidente->prioridade);

        if ($politica === null) {
            return;
        }

        $incidente->prazo_resposta = $incidente->created_at->copy()->addMinutes($politica->tempo_resposta_minutos);
        $incidente->prazo_resolucao = $incidente->created_at->copy()->addMinutes($politica->tempo_resolucao_minutos);
        $incidente->save();
    }
}
