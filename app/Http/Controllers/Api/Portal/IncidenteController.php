<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortalDescricaoResource;
use App\Http\Resources\PortalIncidenteResource;
use App\Models\Incidente;
use App\Models\IncidenteDescricao;
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
                ->where('customer_id', $request->user()->id)
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
