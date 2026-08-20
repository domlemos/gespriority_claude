<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PoliticaSlaResource;
use App\Models\Client;
use App\Models\PoliticaSla;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PoliticaSlaController extends Controller
{
    public function index(Request $request)
    {
        // Escopo de filtro deliberadamente enxuto: `tempo_resposta_minutos`/
        // `tempo_resolucao_minutos` ficam de fora (não são campo de
        // busca/seleção de lista fechada), ver PoliticaSla::scopeFiltros().
        $filtros = $request->validate([
            'nome' => ['sometimes', 'string'],
            'prioridade' => ['sometimes', 'string', Rule::in(PoliticaSla::PRIORIDADES)],
            'ativo' => ['sometimes', 'boolean'],
            'apenas_horas_uteis' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', function ($attribute, $value, $fail) {
                if ($value === 'global') {
                    return;
                }

                if (! is_numeric($value) || ! Client::whereKey($value)->exists()) {
                    $fail('Cliente inválido.');
                }
            }],
        ]);

        return PoliticaSlaResource::collection(
            PoliticaSla::query()
                ->filtros($filtros)
                ->orderBy('client_id')
                ->orderBy('prioridade')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));

        $policy = PoliticaSla::query()->create($data)->refresh();

        return (new PoliticaSlaResource($policy))->response()->setStatusCode(201);
    }

    public function show(PoliticaSla $politica_sla)
    {
        return new PoliticaSlaResource($politica_sla);
    }

    public function update(Request $request, PoliticaSla $politica_sla)
    {
        $data = $request->validate($this->rules($request, $politica_sla));

        $politica_sla->update($data);

        return new PoliticaSlaResource($politica_sla);
    }

    public function destroy(PoliticaSla $politica_sla)
    {
        $politica_sla->delete();

        return response()->noContent();
    }

    /**
     * client_id é lido cru do request (fora do validate) só para poder
     * escopar a regra de unicidade condicional de `prioridade` antes de
     * rodar a validação em si — o próprio client_id ainda é validado
     * normalmente logo abaixo.
     */
    private function rules(Request $request, ?PoliticaSla $politica_sla = null): array
    {
        $clientId = $request->input('client_id', $politica_sla?->client_id);

        return [
            'nome' => ['required', 'string', 'max:255'],
            'prioridade' => [
                'required', 'string', Rule::in(PoliticaSla::PRIORIDADES),
                Rule::unique('politicas_sla', 'prioridade')
                    ->ignore($politica_sla?->id)
                    ->where(fn ($query) => $clientId === null
                        ? $query->whereNull('client_id')
                        : $query->where('client_id', $clientId)),
            ],
            'tempo_resposta_minutos' => ['required', 'integer', 'min:1'],
            'tempo_resolucao_minutos' => ['required', 'integer', 'min:1', 'gte:tempo_resposta_minutos'],
            'apenas_horas_uteis' => ['boolean'],
            'ativo' => ['boolean'],
            'client_id' => ['nullable', 'exists:clients,id'],
        ];
    }
}
