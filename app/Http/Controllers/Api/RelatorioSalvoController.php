<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RelatorioSalvoResource;
use App\Models\Incidente;
use App\Models\RelatorioSalvo;
use App\Relatorios\ConsultaRelatorio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * Carrega os filtros/agrupamento salvos e roda contra os dados atuais.
     * Relatório no formato novo (ou legado com par exato) usa o caminho novo,
     * re-resolvendo o atalho de período aqui, com o "hoje" de agora — validação
     * (`ConsultaRelatorio::regras()`) e execução usam o mesmo relógio. Os demais
     * seguem pela agregação legada de RelatorioController::index().
     */
    public function executar(
        Request $request,
        RelatorioController $controller,
        RelatorioConsultaController $consulta,
        RelatorioSalvo $relatorioSalvo,
    ) {
        $formato = $request->validate([
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ])['formato'] ?? 'json';

        $nova = $relatorioSalvo->consultaNova();
        if ($nova === null) {
            return $controller->responder($relatorioSalvo->filtros, $relatorioSalvo->agrupar_por, $formato, $request->user());
        }

        $dados = [...($relatorioSalvo->filtros ?? []), ...$nova];

        return $consulta->responder(ConsultaRelatorio::deDados($dados), $formato, $request->user());
    }

    /**
     * Com `contar`, valida o formato novo: as regras de ConsultaRelatorio leem
     * campos irmãos no topo do array, então os filtros são achatados para
     * validar e os erros devolvidos com o prefixo `filtros.` que o cliente espera.
     */
    private function validated(Request $request): array
    {
        if (! $request->has('contar')) {
            return $this->validatedLegado($request) + ['contar' => null, 'quebra' => null];
        }

        $base = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'filtros' => ['present', 'array'],
        ]);

        $permitidos = ['periodo', 'data_inicio', 'data_fim', ...ConsultaRelatorio::FILTROS];
        $filtros = array_intersect_key($request->input('filtros', []), array_flip($permitidos));
        $topo = ['contar', 'agrupar_por', 'quebra'];

        $validator = Validator::make(
            [...$filtros, ...$request->only($topo)],
            ConsultaRelatorio::regras(),
        );

        if ($validator->fails()) {
            $erros = [];
            foreach ($validator->errors()->messages() as $campo => $mensagens) {
                $erros[in_array($campo, $topo, true) ? $campo : "filtros.{$campo}"] = $mensagens;
            }
            throw ValidationException::withMessages($erros);
        }

        return [
            'nome' => $base['nome'],
            'contar' => $request->input('contar'),
            'agrupar_por' => $request->input('agrupar_por'),
            'quebra' => $request->input('quebra', 'nenhuma'),
            'filtros' => $filtros,
        ];
    }

    private function validatedLegado(Request $request): array
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
            'filtros.tipo' => ['sometimes', 'string', Rule::in(Incidente::TIPOS)],
            'filtros.data_inicio' => ['sometimes', 'date'],
            'filtros.data_fim' => ['sometimes', 'date'],
            'filtros.categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
            'filtros.subcategoria_id' => ['sometimes', 'integer', 'exists:subcategorias,id'],
            'filtros.item_id' => ['sometimes', 'integer', 'exists:itens,id'],
            'filtros.grupo_solucao_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
            'filtros.grupo_analista_id' => ['sometimes', 'integer', 'exists:grupos_solucao,id'],
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
