<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Client;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteEvento;
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

        $itemChassiGravame = $this->item('Veiculo', 'Gravame', 'Chassi');
        $itemQsaErro = $this->item('Uplink', 'QSA', 'Erro');
        $grupoN1 = GrupoSolucao::query()->where('nome', 'Suporte N1')->first();

        // Incidente já triado e em andamento — demonstra o feed completo:
        // abertura -> escalonamento -> acompanhamento do agente.
        $incidente1 = Incidente::query()->create([
            'customer_id' => $customer->id,
            'item_id' => $itemChassiGravame?->id,
            'grupo_solucao_id' => $grupoN1?->id,
            'responsavel_id' => $agente->id,
            'titulo' => 'Consulta de gravame não retorna o chassi informado',
            'prioridade' => 'media',
            'origem' => 'portal',
            'status' => 'em_andamento',
        ]);
        $incidente1->forceFill(['criado_por_id' => $admin->id])->save();
        $this->calcularPrazosSla($incidente1);
        // "em_andamento" pressupõe que já saiu de 'aberto' — mesma regra de
        // IncidenteController::registrarTransicaoDeStatus().
        $incidente1->forceFill(['respondido_em' => $incidente1->created_at])->save();
        if ($grupoN1) {
            IncidenteEvento::query()->create([
                'incidente_id' => $incidente1->id,
                'user_id' => $admin->id,
                'tipo' => IncidenteEvento::TIPO_ENCAMINHADO_GRUPO,
                'alvo_type' => GrupoSolucao::class,
                'alvo_id' => $grupoN1->id,
            ]);
        }

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente1->id,
            'user_id' => $admin->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Cliente reporta que a consulta de gravame não está retornando o chassi do veículo, mesmo com o gravame localizado.',
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
            'descricao' => 'Confirmado, a fonte do gravame não está retornando o campo de chassi. Escalonando para o time de dados.',
        ]);

        // Incidente recém-aberto, ainda sem triagem — feed com só a abertura.
        $incidente2 = Incidente::query()->create([
            'customer_id' => $customer->id,
            'item_id' => $itemQsaErro?->id,
            'grupo_solucao_id' => null,
            'responsavel_id' => null,
            'titulo' => 'Consulta de QSA retornando erro para todos os clientes',
            'prioridade' => 'urgente',
            'origem' => 'telefone',
            'status' => 'aberto',
        ]);
        $incidente2->forceFill(['criado_por_id' => $admin->id])->save();
        $this->calcularPrazosSla($incidente2);

        IncidenteDescricao::query()->create([
            'incidente_id' => $incidente2->id,
            'user_id' => $admin->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => 'Nenhuma consulta de QSA está retornando resultado desde hoje de manhã, afetando todos os clientes.',
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

        $itemDossieDetalhadoFalha = $this->item('Dossie', 'Detalhado', 'Falha');
        $itemDossieAnaliticoDivergencia = $this->item('Dossie', 'Analítico', 'Divergência');
        $itemUplinkQsaLentidao = $this->item('Uplink', 'QSA', 'Lentidão');
        $itemDossieUpscoreErro = $this->item('Dossie', 'Upscore', 'Erro');
        $itemUpminerUpacademyErro = $this->item('Upminer', 'Upacademy', 'Erro');
        $itemFonteMonitoramentoPreventivo = $this->item('Fonte', 'Monitoramento', 'Preventivo');
        $itemDossieWorkflowAprovacao = $this->item('Dossie', 'Workflow', 'Aprovação');
        $itemVeiculoChassiErro = $this->item('Veiculo', 'Chassi', 'Erro');
        $itemDossieFonteErro = $this->item('Dossie', 'Fonte', 'Erro');

        // Segundo cliente — só pra dar sentido de verdade ao filtro
        // `client_id` dos relatórios (com um cliente só, o filtro nunca
        // muda o resultado).
        $segundoClient = Client::query()->firstOrCreate(['name' => 'TechCorp Soluções']);
        $segundoCustomer = Customer::query()->updateOrCreate(
            ['email' => 'financeiro@techcorp.example.com'],
            ['name' => 'Financeiro TechCorp', 'client_id' => $segundoClient->id, 'password' => Hash::make('password')]
        );

        // {titulo, item, grupo, responsavel, prioridade, origem, status, dias atrás da abertura, horas até a conclusão (null = sem SLA), customer, criador}
        $incidentes = [
            ['Dossiê detalhado retorna com falha para o CPF informado', $itemDossieDetalhadoFalha, $grupoN1, $agente, 'alta', 'telefone', 'fechado', 10, 7, $customerPrincipal, $agente],
            ['Divergência de dados no dossiê analítico', $itemDossieAnaliticoDivergencia, $grupoN2, $supervisor, 'media', 'portal', 'resolvido', 8, 30, $customerPrincipal, $admin],
            ['Consulta de QSA extremamente lenta', $itemUplinkQsaLentidao, $grupoRedes, $agente, 'urgente', 'email', 'fechado', 5, 3, $customerPrincipal, $agente],
            ['Erro ao calcular o Upscore do cliente', $itemDossieUpscoreErro, $grupoN1, $admin, 'baixa', 'chat', 'cancelado', 3, 1, $customerPrincipal, $supervisor],
            ['Erro ao acessar o Upminer Upacademy', $itemUpminerUpacademyErro, $grupoN2, null, 'alta', 'portal', 'fechado', 15, 20, $customerPrincipal, $admin],
            ['Manutenção preventiva de fonte não executada no prazo', $itemFonteMonitoramentoPreventivo, $grupoRedes, $agente, 'baixa', 'presencial', 'resolvido', 20, 40, $customerPrincipal, $agente],
            ['Fluxo de aprovação do dossiê travado', $itemDossieWorkflowAprovacao, null, $supervisor, 'media', 'portal', 'fechado', 2, 10, $segundoCustomer, $supervisor],
            ['Erro ao consultar chassi do veículo', $itemVeiculoChassiErro, $grupoN1, $agente, 'baixa', 'portal', 'fechado', 1, null, $customerPrincipal, $agente],
            ['Erro ao consultar fonte do dossiê', $itemDossieFonteErro, $grupoN2, $admin, 'media', 'monitoramento', 'fechado', 25, 30, $segundoCustomer, $admin],
            ['Chamado sem classificação de item', null, $grupoN1, $agente, 'media', 'telefone', 'fechado', 4, 10, $customerPrincipal, $agente],
        ];

        foreach ($incidentes as $index => [$titulo, $item, $grupo, $responsavel, $prioridade, $origem, $status, $diasAtras, $horasAteConcluir, $customer, $criador]) {
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
            $incidente->forceFill(['criado_por_id' => $criador->id])->save();

            if ($grupo) {
                IncidenteEvento::query()->create([
                    'incidente_id' => $incidente->id,
                    'user_id' => $criador->id,
                    'tipo' => IncidenteEvento::TIPO_ENCAMINHADO_GRUPO,
                    'alvo_type' => GrupoSolucao::class,
                    'alvo_id' => $grupo->id,
                ]);
            }

            if ($responsavel) {
                IncidenteEvento::query()->create([
                    'incidente_id' => $incidente->id,
                    'user_id' => $criador->id,
                    'tipo' => IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL,
                    'alvo_type' => User::class,
                    'alvo_id' => $responsavel->id,
                ]);
            }

            // 'cancelado' nunca passou por 'resolvido'/'fechado' — sem
            // evento de conclusão (mas os de encaminhamento acima continuam
            // valendo normalmente).
            if ($status === 'cancelado') {
                continue;
            }

            // Pro primeiro incidente ("Dossiê detalhado retorna com falha..."), simula o
            // cenário de reabertura que motivou a generalização desta
            // tabela: resolvido pelo agente, reaberto, resolvido de novo
            // pelo supervisor — as DUAS resoluções ficam registradas, não
            // só a mais recente.
            if ($index === 0) {
                IncidenteEvento::query()->create(['incidente_id' => $incidente->id, 'user_id' => $agente->id, 'tipo' => IncidenteEvento::TIPO_RESOLVIDO]);
                IncidenteEvento::query()->create(['incidente_id' => $incidente->id, 'user_id' => $supervisor->id, 'tipo' => IncidenteEvento::TIPO_RESOLVIDO]);
                IncidenteEvento::query()->create(['incidente_id' => $incidente->id, 'user_id' => $supervisor->id, 'tipo' => IncidenteEvento::TIPO_FECHADO]);

                continue;
            }

            $autorConclusao = ($responsavel ?? $admin)->id;

            IncidenteEvento::query()->create([
                'incidente_id' => $incidente->id,
                'user_id' => $autorConclusao,
                'tipo' => IncidenteEvento::TIPO_RESOLVIDO,
            ]);

            if ($status === 'fechado') {
                IncidenteEvento::query()->create([
                    'incidente_id' => $incidente->id,
                    'user_id' => $autorConclusao,
                    'tipo' => IncidenteEvento::TIPO_FECHADO,
                ]);
            }
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

    /**
     * Busca um item pelo caminho completo (categoria > subcategoria > item)
     * — a taxonomia atual repete nomes de item entre subcategorias (ex:
     * "Erro"), então `Item::where('nome', ...)` sozinho seria ambíguo.
     */
    private function item(string $categoria, string $subcategoria, string $itemNome): ?Item
    {
        return Categoria::query()->where('nome', $categoria)->first()
            ?->subcategorias()->where('nome', $subcategoria)->first()
            ?->itens()->where('nome', $itemNome)->first();
    }
}
