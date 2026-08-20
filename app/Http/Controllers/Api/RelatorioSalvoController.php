<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RelatorioSalvoResource;
use App\Models\Incidente;
use App\Models\RelatorioSalvo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RelatorioSalvoController extends Controller
{
    public function index(Request $request)
    {
        return RelatorioSalvoResource::collection(
            RelatorioSalvo::query()
                ->with('user')
                ->orderBy('nome')
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $relatorio = RelatorioSalvo::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return (new RelatorioSalvoResource($relatorio->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(RelatorioSalvo $relatorioSalvo)
    {
        return new RelatorioSalvoResource($relatorioSalvo->load('user'));
    }

    public function update(Request $request, RelatorioSalvo $relatorioSalvo)
    {
        $relatorioSalvo->update($this->validated($request));

        return new RelatorioSalvoResource($relatorioSalvo->load('user'));
    }

    public function destroy(RelatorioSalvo $relatorioSalvo)
    {
        $relatorioSalvo->delete();

        return response()->noContent();
    }

    /**
     * Carrega os filtros/agrupamento salvos e roda contra os dados atuais —
     * mesma lógica de agregação de RelatorioController::index(), só que os
     * parâmetros vêm do registro salvo em vez da query string.
     */
    public function executar(Request $request, RelatorioController $controller, RelatorioSalvo $relatorioSalvo)
    {
        $formato = $request->validate([
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ])['formato'] ?? 'json';

        return $controller->responder($relatorioSalvo->filtros, $relatorioSalvo->agrupar_por, $formato);
    }

    private function validated(Request $request): array
    {
        $validado = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'agrupar_por' => ['required', 'string', Rule::in(RelatorioSalvo::AGRUPAMENTOS)],
            // 'present', não 'required' — um relatório sem filtro nenhum
            // (ex. "todos os incidentes por item") é um caso de uso válido;
            // 'required' rejeitaria um array vazio ([]/{}), que é
            // exatamente esse caso.
            'filtros' => ['present', 'array'],
            'filtros.status' => ['sometimes', 'string', Rule::in(Incidente::STATUSES)],
            'filtros.data_inicio' => ['sometimes', 'date'],
            'filtros.data_fim' => ['sometimes', 'date'],
            'filtros.categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
            'filtros.subcategoria_id' => ['sometimes', 'integer', 'exists:subcategorias,id'],
            'filtros.item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'filtros.grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'filtros.responsavel_id' => ['sometimes', 'integer', 'exists:users,id'],
            'filtros.client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'filtros.customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
        ]);

        // $request->validate() reconstrói 'filtros' a partir das sub-chaves
        // 'filtros.*' individualmente validadas — com filtros vazio, nenhuma
        // sub-chave existe pra reconstruir e a chave pai inteira some do
        // array retornado, mesmo tendo passado a regra 'present'. Usa o
        // input bruto (já validado como array acima) em vez do
        // reconstruído, pra não perder um filtro vazio válido.
        $validado['filtros'] = $request->input('filtros', []);

        return $validado;
    }
}
