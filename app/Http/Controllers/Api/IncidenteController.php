<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidenteResource;
use App\Models\Customer;
use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteEvento;
use App\Models\Item;
use App\Models\PoliticaSla;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidenteController extends Controller
{
    private const RELATIONS = ['customer', 'item', 'grupoSolucao', 'responsavel'];

    // App roda com config('app.timezone') = 'UTC' (created_at/updated_at,
    // cálculo de prazo de SLA, etc. continuam em UTC de propósito — não é
    // pra mexer nisso). O texto "às HH:mm do dia DD/MM/AAAA" embutido nas
    // mensagens de log do feed é a exceção: é conteúdo exibido pro agente,
    // então precisa refletir o horário local de Brasília, não UTC.
    private const TIMEZONE_EXIBICAO = 'America/Sao_Paulo';

    public function index(Request $request)
    {
        $filtros = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Incidente::STATUSES)],
            'prioridade' => ['sometimes', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
            'origem' => ['sometimes', 'string', Rule::in(Incidente::ORIGENS)],
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        return IncidenteResource::collection(
            Incidente::query()
                ->filtros($filtros)
                ->with(self::RELATIONS)
                ->latest()
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'item_id' => ['nullable', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['nullable', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['nullable', 'integer', 'exists:users,id'],
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['required', 'string'],
            'prioridade' => ['required', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
            'origem' => ['required', 'string', Rule::in(Incidente::ORIGENS)],
        ]);

        // status nunca vem do cliente na criação — todo incidente nasce
        // "aberto" (ver BACKEND_SPECS.md seção 3.1); qualquer 'status' no
        // payload aqui é ignorado, mesmo que enviado.
        $descricaoTexto = $data['descricao'];
        unset($data['descricao']);

        // `incidentes` e `incidente_descricoes` são abastecidas juntas — a
        // descrição de abertura já nasce como a primeira entrada do feed
        // (tipo 'comentario', autor = quem está criando), não um campo
        // separado no Incidente. Transação: se a segunda escrita falhar, a
        // primeira não fica órfã.
        $incidente = DB::transaction(function () use ($data, $descricaoTexto, $request) {
            $incidente = Incidente::query()->create([...$data, 'status' => 'aberto']);
            // criado_por_id fora do #[Fillable(...)] de propósito (mesmo
            // padrão de prazo_resposta/etc.) — quem abriu nunca vem do
            // payload, é sempre quem está autenticado.
            $incidente->criado_por_id = $request->user()->id;
            $incidente->save();

            $incidente->descricoes()->create([
                'user_id' => $request->user()->id,
                'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
                'descricao' => $descricaoTexto,
            ]);

            $this->calcularPrazosSla($incidente);

            return $incidente;
        });

        return (new IncidenteResource($incidente->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Incidente $incidente)
    {
        return new IncidenteResource($incidente->load(self::RELATIONS));
    }

    public function update(Request $request, Incidente $incidente)
    {
        // Update parcial de propósito (diferente de Categoria/PoliticaSla/etc,
        // que exigem reenviar o recurso inteiro): um agente frequentemente só
        // quer mudar o status, sem reenviar título/descrição a cada PUT.
        $data = $request->validate([
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'item_id' => ['sometimes', 'nullable', 'integer', 'exists:itens,id'],
            'grupo_solucao_id' => ['sometimes', 'nullable', 'integer', 'exists:grupos_solucao,id'],
            'responsavel_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'titulo' => ['sometimes', 'required', 'string', 'max:255'],
            'prioridade' => ['sometimes', 'required', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
            'origem' => ['sometimes', 'required', 'string', Rule::in(Incidente::ORIGENS)],
            'status' => ['sometimes', 'required', 'string', Rule::in(Incidente::STATUSES)],
        ]);

        $grupoAnteriorId = $incidente->grupo_solucao_id;
        $responsavelAnteriorId = $incidente->responsavel_id;
        $statusAnterior = $incidente->status;
        $tituloAnterior = $incidente->titulo;
        $prioridadeAnterior = $incidente->prioridade;
        $origemAnterior = $incidente->origem;
        $customerAnteriorId = $incidente->customer_id;
        $itemAnteriorId = $incidente->item_id;

        DB::transaction(function () use (
            $incidente,
            $data,
            $grupoAnteriorId,
            $responsavelAnteriorId,
            $statusAnterior,
            $tituloAnterior,
            $prioridadeAnterior,
            $origemAnterior,
            $customerAnteriorId,
            $itemAnteriorId,
            $request
        ) {
            $incidente->update($data);

            $this->registrarTransicaoDeStatus($incidente, $statusAnterior, $incidente->status);
            $this->registrarEventoDeConclusaoSeAplicavel($incidente, $request->user(), $statusAnterior, $incidente->status);

            $this->logEscalonamentoSeMudou(
                $incidente,
                $request->user(),
                $grupoAnteriorId,
                $incidente->grupo_solucao_id,
                fn (GrupoSolucao $grupo) => "Encaminhado para o grupo '{$grupo->nome}' às ".$this->agoraLocal()->format('H:i').' do dia '.$this->agoraLocal()->format('d/m/Y').'.',
                GrupoSolucao::class,
                IncidenteEvento::TIPO_ENCAMINHADO_GRUPO,
            );

            $this->logEscalonamentoSeMudou(
                $incidente,
                $request->user(),
                $responsavelAnteriorId,
                $incidente->responsavel_id,
                fn (User $responsavel) => "Atribuído para {$responsavel->name} às ".$this->agoraLocal()->format('H:i').' do dia '.$this->agoraLocal()->format('d/m/Y').'.',
                User::class,
                IncidenteEvento::TIPO_ENCAMINHADO_RESPONSAVEL,
            );

            // Toda alteração de campo "simples" (fora grupo/responsável, que já
            // ganham a mensagem mais específica acima) também vira uma entrada
            // no feed — auditoria de ITSM: precisa dar pra reconstruir o
            // histórico completo de mudanças de um chamado, não só quem foi
            // escalonado pra quem.
            if (array_key_exists('titulo', $data)) {
                $this->logAlteracaoSeMudou($incidente, $request->user(), 'Título', $tituloAnterior, $incidente->titulo);
            }

            if (array_key_exists('prioridade', $data)) {
                $this->logAlteracaoSeMudou($incidente, $request->user(), 'Prioridade', $prioridadeAnterior, $incidente->prioridade);
            }

            if (array_key_exists('origem', $data)) {
                $this->logAlteracaoSeMudou($incidente, $request->user(), 'Origem', $origemAnterior, $incidente->origem);
            }

            if (array_key_exists('status', $data)) {
                $this->logAlteracaoSeMudou($incidente, $request->user(), 'Status', $statusAnterior, $incidente->status);
            }

            if (array_key_exists('customer_id', $data)) {
                $this->logAlteracaoSeMudou(
                    $incidente,
                    $request->user(),
                    'Cliente',
                    Customer::query()->find($customerAnteriorId)?->name,
                    Customer::query()->find($incidente->customer_id)?->name,
                );
            }

            if (array_key_exists('item_id', $data)) {
                $this->logAlteracaoSeMudou(
                    $incidente,
                    $request->user(),
                    'Item',
                    Item::query()->find($itemAnteriorId)?->nome,
                    Item::query()->find($incidente->item_id)?->nome,
                );
            }
        });

        return new IncidenteResource($incidente->load(self::RELATIONS));
    }

    /**
     * `respondido_em` marca a 1ª saída de 'aberto' (mesmo que direto pra um
     * status concluído); `concluido_em` marca a 1ª entrada num status de
     * `Incidente::STATUS_CONCLUIDOS`. Nenhum dos dois é sobrescrito por uma
     * conclusão *subsequente* (ex. `resolvido` → `fechado` não mexe em
     * `concluido_em` já setado) — são marcos de "primeira vez". Reabertura
     * é a exceção: ver `concluido_em` sendo limpo abaixo.
     */
    private function registrarTransicaoDeStatus(Incidente $incidente, string $anterior, string $novo): void
    {
        if ($anterior === $novo) {
            return;
        }

        $mudou = false;

        if ($anterior === 'aberto' && $incidente->respondido_em === null) {
            $incidente->respondido_em = now();
            $mudou = true;
        }

        if (in_array($novo, Incidente::STATUS_CONCLUIDOS, true) && $incidente->concluido_em === null) {
            $incidente->concluido_em = now();
            $mudou = true;
        }

        // Reabertura: saiu de um status concluído pra um não-concluído.
        // Sem isso, statusSlaResolucao() ficaria congelado comparando contra
        // o concluido_em antigo mesmo com o incidente ativo de novo — bug
        // real encontrado depois da entrega inicial de SLA. respondido_em
        // não é limpo aqui: "primeira resposta" continua sendo um fato
        // histórico, não é desfeito por reabertura.
        if (in_array($anterior, Incidente::STATUS_CONCLUIDOS, true)
            && ! in_array($novo, Incidente::STATUS_CONCLUIDOS, true)
            && $incidente->concluido_em !== null) {
            $incidente->concluido_em = null;
            $mudou = true;
        }

        if ($mudou) {
            $incidente->save();
        }
    }

    /**
     * Um registro por transição pra 'resolvido'/'fechado' — nunca
     * sobrescrito nem limpo na reabertura (diferente de `concluido_em`, ver
     * `registrarTransicaoDeStatus()` acima). Um chamado resolvido, reaberto
     * e resolvido de novo (por agentes diferentes ou não) gera duas linhas
     * aqui, cada uma com seu autor e data — é isso que permite aos
     * relatórios `agrupar_por=resolvido_por`/`fechado_por` (ver
     * RelatorioController) responder "quem resolveu"/"quem fechou"
     * corretamente mesmo depois de reaberturas subsequentes, ao contrário
     * de uma coluna única em `Incidente`, que só guardaria a mais recente.
     * `cancelado` fica de fora — não foi pedido um indicador "cancelado
     * por" ainda.
     */
    private function registrarEventoDeConclusaoSeAplicavel(Incidente $incidente, User $autor, string $anterior, string $novo): void
    {
        if ($anterior === $novo || ! in_array($novo, [IncidenteEvento::TIPO_RESOLVIDO, IncidenteEvento::TIPO_FECHADO], true)) {
            return;
        }

        IncidenteEvento::query()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor->id,
            'tipo' => $novo,
        ]);
    }

    /**
     * Calcula e congela prazo_resposta/prazo_resolucao a partir da política
     * de SLA aplicável no momento da abertura (ver Client::resolvedSlaFor())
     * — nunca recalculado depois, mesmo que a política mude. Fica `null`
     * (sem_sla) se não houver política aplicável pra essa prioridade.
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
     * Cria uma entrada 'escalonamento' no feed (texto livre, pra leitura
     * humana no histórico do chamado) **e** um `IncidenteEvento` estruturado
     * (`$tipoEvento` + `alvo_type`/`alvo_id`, pra agregação em relatório —
     * ver `agrupar_por=encaminhado_por`/`encaminhado_para_grupo`/
     * `encaminhado_para_responsavel`) quando `$novoId` muda em relação a
     * `$anteriorId` e aponta pra um registro de verdade (não loga
     * desatribuição/limpeza, só escalonamento pra um grupo/responsável
     * concreto) — usado tanto pra grupo_solucao_id quanto responsavel_id.
     */
    private function logEscalonamentoSeMudou(
        Incidente $incidente,
        User $autor,
        ?int $anteriorId,
        ?int $novoId,
        \Closure $mensagem,
        string $model,
        string $tipoEvento,
    ): void {
        if ($novoId === null || $novoId === $anteriorId) {
            return;
        }

        $alvo = $model::query()->find($novoId);

        $incidente->descricoes()->create([
            'user_id' => $autor->id,
            'tipo' => IncidenteDescricao::TIPO_ESCALONAMENTO,
            'descricao' => $mensagem($alvo),
        ]);

        IncidenteEvento::query()->create([
            'incidente_id' => $incidente->id,
            'user_id' => $autor->id,
            'tipo' => $tipoEvento,
            'alvo_type' => $model,
            'alvo_id' => $novoId,
        ]);
    }

    /**
     * Log genérico de "de X para Y" pra qualquer campo simples que mudou —
     * cobre título/prioridade/origem/status/cliente/item. `null` (ex. item
     * removido/nunca setado) aparece como "(nenhum)" em vez de string vazia.
     * Sem mudança de valor, não cria entrada (evita spam no feed em updates
     * que reenviam o mesmo valor).
     */
    private function logAlteracaoSeMudou(
        Incidente $incidente,
        User $autor,
        string $campo,
        ?string $valorAnterior,
        ?string $valorNovo,
    ): void {
        if ($valorAnterior === $valorNovo) {
            return;
        }

        // "Campo '{$campo}' alterado..." (não "{$campo} alterado...") de
        // propósito — 'campo' é sempre masculino, então a frase concorda
        // certo em português não importa o gênero do nome do campo em si
        // ('Prioridade'/'Origem' são femininos, 'Status'/'Título' não).
        $incidente->descricoes()->create([
            'user_id' => $autor->id,
            'tipo' => IncidenteDescricao::TIPO_ALTERACAO,
            'descricao' => "Campo '{$campo}' alterado de '".($valorAnterior ?? '(nenhum)')."' para '"
                .($valorNovo ?? '(nenhum)')."' às ".$this->agoraLocal()->format('H:i').' do dia '.$this->agoraLocal()->format('d/m/Y').'.',
        ]);
    }

    /** "Agora" no horário de Brasília, só pra texto exibido — ver TIMEZONE_EXIBICAO. */
    private function agoraLocal(): Carbon
    {
        return now(self::TIMEZONE_EXIBICAO);
    }
}
