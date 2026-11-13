<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortalDescricaoResource;
use App\Http\Resources\PortalIncidenteResource;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
use App\Models\IncidenteEvento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Abertura, listagem, detalhe e comentários pelo portal do cliente (guard
 * "customer"). Separado do IncidenteController do staff de propósito: lá o
 * payload aceita classificação, encaminhamento e origem, que o cliente não
 * pode tocar. Aqui o cliente só manda título e descrição — o resto é fixado
 * no servidor, e por auditoria ele só abre chamado em nome próprio (ver
 * BACKEND_SPECS.md §3.4.10).
 */
class IncidenteController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'busca' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(Incidente::STATUSES)],
        ]);

        $busca = trim($filtros['busca'] ?? '');

        // Escopo sempre pelo token, nunca por parâmetro — inclui os chamados
        // que o staff abriu em nome do cliente. A busca casa o número exato
        // ou um trecho do título (sem diferenciar maiúsculas).
        return PortalIncidenteResource::collection(
            Incidente::query()
                ->where('customer_id', $request->user()->id)
                ->when($busca !== '', fn ($q) => $q->where(function ($q) use ($busca) {
                    $q->whereRaw('LOWER(titulo) LIKE ?', ['%'.mb_strtolower($busca).'%']);

                    if (ctype_digit($busca)) {
                        $q->orWhere('id', (int) $busca);
                    }
                }))
                ->when($filtros['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->latest()
                ->paginate(15)
        );
    }

    public function show(Request $request, Incidente $incidente)
    {
        $this->garantirDono($request, $incidente);

        // O feed do staff é interno. O cliente vê só a descrição de abertura
        // (a primeira entrada, mesmo quando o staff abriu em nome dele), os
        // próprios comentários e a mensagem de encerramento de quem fechou.
        $aberturaId = $incidente->descricoes()->withoutTrashed()->min('id');

        $descricoes = IncidenteDescricao::query()
            ->where('incidente_id', $incidente->id)
            ->where(fn ($q) => $q
                // Só os comentários do cliente: a linha de "status alterado"
                // gravada na reabertura (autoria dele) é registro do staff.
                ->where(fn ($qc) => $qc->where('customer_id', $request->user()->id)->where('tipo', IncidenteDescricao::TIPO_COMENTARIO))
                ->orWhere('tipo', IncidenteDescricao::TIPO_ENCERRAMENTO)
                ->orWhere('id', $aberturaId))
            ->with(['user:id,name', 'customer:id,name'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return new PortalIncidenteResource($incidente->setRelation('descricoesPortal', $descricoes));
    }

    public function comentar(Request $request, Incidente $incidente)
    {
        $this->garantirDono($request, $incidente);

        $data = $request->validate([
            'descricao' => ['required', 'string', 'max:5000'],
        ]);

        if (in_array($incidente->status, ['fechado', 'cancelado'], true)) {
            throw ValidationException::withMessages([
                'descricao' => 'Este chamado já foi encerrado e não aceita novos comentários.',
            ]);
        }

        $descricao = $incidente->descricoes()->create([
            'customer_id' => $request->user()->id,
            'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
            'descricao' => $data['descricao'],
        ]);

        return (new PortalDescricaoResource($descricao->load('customer:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Reabertura pelo cliente: só chamado `fechado` e dentro do prazo
     * (Incidente::reaberturaPortalPermitidaAte()), com motivo obrigatório.
     * Volta para `aberto` mantendo grupo e responsável — retorna à fila de
     * quem já atendeu. Mesmos efeitos colaterais de uma reabertura feita pelo
     * staff (IncidenteController::update()): `concluido_em` limpo (o SLA de
     * resolução volta a contar contra o prazo original), evento `reaberto`
     * (aqui com autoria de cliente) e a alteração de status no feed, no mesmo
     * formato que o backfill de eventos lê. O motivo vira um comentário do
     * cliente, visível ao staff e no portal.
     */
    public function reabrir(Request $request, Incidente $incidente)
    {
        $this->garantirDono($request, $incidente);

        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:5000'],
        ]);

        $motivo = trim($data['motivo']);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Informe o motivo da reabertura.']);
        }

        if ($incidente->status !== 'fechado') {
            throw ValidationException::withMessages([
                'status' => 'Só chamados fechados podem ser reabertos.',
            ]);
        }

        $ate = $incidente->reaberturaPortalPermitidaAte();
        if ($ate === null || $ate->isPast()) {
            throw ValidationException::withMessages([
                'status' => 'O prazo de '.Incidente::PRAZO_REABERTURA_PORTAL_DIAS.' dias para reabrir este chamado terminou. Abra um novo chamado.',
            ]);
        }

        $customer = $request->user();

        DB::transaction(function () use ($incidente, $customer, $motivo) {
            $incidente->status = 'aberto';
            $incidente->concluido_em = null;
            $incidente->save();

            $agora = now(config('app.timezone_exibicao'));

            $incidente->descricoes()->create([
                'customer_id' => $customer->id,
                'tipo' => IncidenteDescricao::TIPO_ALTERACAO,
                'descricao' => "Campo 'Status' alterado de 'fechado' para 'aberto' às ".$agora->format('H:i').' do dia '.$agora->format('d/m/Y').'.',
            ]);

            $incidente->descricoes()->create([
                'customer_id' => $customer->id,
                'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
                'descricao' => "Chamado reaberto pelo cliente. Motivo: {$motivo}",
            ]);

            IncidenteEvento::query()->create([
                'incidente_id' => $incidente->id,
                'user_id' => null,
                'autor_customer_id' => $customer->id,
                'tipo' => IncidenteEvento::TIPO_REABERTO,
            ]);
        });

        return $this->show($request, $incidente->refresh());
    }

    // 404, não 403: chamado de outro cliente nem "existe" para quem pergunta.
    private function garantirDono(Request $request, Incidente $incidente): void
    {
        abort_unless($incidente->customer_id === $request->user()->id, 404);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['required', 'string'],
        ]);

        $customer = $request->user();

        // Chamado nasce sem classificação (item/prioridade/prazos nulos): o
        // staff classifica na triagem, e o update() do staff deriva
        // prioridade e prazos nesse momento — contando desde created_at, ou
        // seja, desde a abertura.
        $incidente = DB::transaction(function () use ($data, $customer) {
            $incidente = Incidente::query()->create([
                'customer_id' => $customer->id,
                'titulo' => $data['titulo'],
                'tipo' => 'incidente',
                'origem' => 'portal',
                'status' => 'aberto',
            ]);
            // Fora do #[Fillable(...)], mesmo padrão de criado_por_id.
            $incidente->criado_por_customer_id = $customer->id;
            $incidente->save();

            $incidente->descricoes()->create([
                'customer_id' => $customer->id,
                'tipo' => IncidenteDescricao::TIPO_COMENTARIO,
                'descricao' => $data['descricao'],
            ]);

            return $incidente;
        });

        return (new PortalIncidenteResource($incidente))
            ->response()
            ->setStatusCode(201);
    }
}
