<?php

namespace App\Http\Controllers\Api;

use App\Exports\IndicadoresExport;
use App\Exports\RelatorioChamadosExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Relatorios\Indicadores\AgregadorIndicadores;
use App\Relatorios\Indicadores\ConsultaIndicadores;
use App\Relatorios\Indicadores\FonteIndicadores;
use App\Relatorios\Indicadores\LinhaIndicador;
use App\Relatorios\LinhaBase;
use App\Relatorios\ListaChamados;
use App\Support\Auditoria;
use App\Support\PeriodoRelatorio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `GET /relatorios/indicadores` — abertos, resolvidos, fechados e cancelados
 * por grupo de solução (ou por integrante de um grupo), contados no grupo do
 * autor no momento do evento. Ver BACKEND_SPECS §3.4.9 "Indicadores por grupo".
 */
class IndicadoresController extends Controller
{
    public function index(Request $request): JsonResponse|BinaryFileResponse
    {
        // `formato` na mesma passada dos demais campos: um único 422 com todos os erros.
        $validado = $request->validate(ConsultaIndicadores::regras() + [
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ]);

        return $this->responder(ConsultaIndicadores::deDados($validado), $validado['formato'] ?? 'json', $request->user());
    }

    /**
     * Chamados por trás de uma célula (linha × indicador [× coluna]). Recebe os
     * mesmos parâmetros da consulta + `chave`/`indicador`/`coluna`, e parte das
     * mesmas `LinhaIndicador` do número, para a lista bater com a célula.
     * Um item por chamado, com o evento mais recente no escopo.
     */
    public function chamados(Request $request): JsonResponse|BinaryFileResponse
    {
        $validado = $request->validate(ConsultaIndicadores::regras() + [
            'chave' => ['required', 'string', 'max:191'],
            'indicador' => ['required', 'string', Rule::in(array_keys(FonteIndicadores::TIPOS))],
            'coluna' => ['sometimes', 'nullable', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ]);
        $c = ConsultaIndicadores::deDados($validado);
        $u = $request->user();
        $c->validarVisibilidade($u);

        $colunas = PeriodoRelatorio::colunas($c->inicio, $c->fim, $c->quebra);
        $coluna = $validado['coluna'] ?? null;
        if ($coluna !== null && ! isset($colunas[$coluna])) {
            throw ValidationException::withMessages(['coluna' => 'Coluna inexistente neste período.']);
        }

        $chave = $validado['chave'];
        $indicador = $validado['indicador'];
        $linhas = FonteIndicadores::linhas($c, $u);
        $corpo = AgregadorIndicadores::agregar($c, $linhas, $u);
        $rotulo = collect($corpo['linhas'])->firstWhere('chave', $chave)['rotulo'] ?? "#{$chave}";
        $rotuloIndicador = collect($corpo['indicadores'])->firstWhere('chave', $indicador)['rotulo'];
        $colunaInfo = $coluna === null ? null : ['chave' => $coluna, 'rotulo' => $colunas[$coluna]];
        $selecionadas = $this->selecionar($c, $linhas, $chave, $indicador, $coluna);

        if (($validado['formato'] ?? 'json') === 'xlsx') {
            $descricao = "Linha: {$rotulo} · {$rotuloIndicador}".($colunaInfo ? " · {$colunaInfo['rotulo']}" : '');

            Auditoria::registrar(
                'exportado',
                alteracoes: ['parametros' => $request->except('formato')],
                entidadeType: 'relatorio',
                entidadeLabel: "{$corpo['titulo']} — {$descricao}",
            );

            return Excel::download(
                new RelatorioChamadosExport($corpo['titulo'], $descricao, ListaChamados::montar($u, $selecionadas, false), false, $u, now()),
                'indicadores-chamados.xlsx'
            );
        }

        $porPagina = (int) ($validado['per_page'] ?? 50);
        $pagina = new LengthAwarePaginator(
            $selecionadas->forPage((int) ($validado['page'] ?? 1), $porPagina)->values(),
            $selecionadas->count(),
            $porPagina,
            (int) ($validado['page'] ?? 1),
        );

        return response()->json([
            'titulo' => $corpo['titulo'],
            'linha' => ['chave' => $chave, 'rotulo' => $rotulo],
            'indicador' => ['chave' => $indicador, 'rotulo' => $rotuloIndicador],
            'coluna' => $colunaInfo,
            'data' => ListaChamados::montar($u, $pagina->getCollection(), false),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'last_page' => $pagina->lastPage(),
            ],
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Público para o relatório salvo executar a mesma consulta. Valida a
     * visibilidade aqui (e não em `index()`) para nenhum chamador esquecer:
     * com `grupos`, a fonte filtra só pelos grupos pedidos.
     *
     * @throws ValidationException
     */
    public function responder(ConsultaIndicadores $c, string $formato, User $u): JsonResponse|BinaryFileResponse
    {
        $c->validarVisibilidade($u);

        $corpo = AgregadorIndicadores::agregar($c, FonteIndicadores::linhas($c, $u), $u);

        if ($formato === 'xlsx') {
            Auditoria::registrar(
                'exportado',
                alteracoes: ['parametros' => $this->parametros($c)],
                entidadeType: 'relatorio',
                entidadeLabel: $corpo['titulo'],
            );

            return Excel::download(new IndicadoresExport($corpo, $u, now()), 'relatorio-indicadores.xlsx');
        }

        return response()->json($corpo);
    }

    /**
     * Parâmetros da consulta como executada (e não da requisição, que no
     * relatório salvo é só o `executar`), para o log de auditoria.
     *
     * @return array<string, mixed>
     */
    private function parametros(ConsultaIndicadores $c): array
    {
        return [
            'grupos' => $c->grupos,
            'por_integrante' => $c->porIntegrante,
            'quebra' => $c->quebra,
            'periodo' => $c->atalho,
            'data_inicio' => $c->inicio,
            'data_fim' => $c->fim,
        ];
    }

    /**
     * Linhas-base da célula, mais recentes primeiro, uma por chamado.
     *
     * @param  Collection<int, LinhaIndicador>  $linhas
     * @return Collection<int, LinhaBase>
     */
    private function selecionar(ConsultaIndicadores $c, Collection $linhas, string $chave, string $indicador, ?string $coluna): Collection
    {
        return $linhas
            ->filter(fn (LinhaIndicador $l) => $l->indicador === $indicador
                && (string) $l->chave === $chave
                && ($coluna === null || PeriodoRelatorio::colunaDe($l->base->data, $c->quebra) === $coluna))
            ->map(fn (LinhaIndicador $l) => $l->base)
            ->sortByDesc(fn (LinhaBase $l) => [$l->data->getTimestamp(), $l->eventoId ?? 0, $l->incidenteId])
            ->unique('incidenteId')
            ->values();
    }
}
