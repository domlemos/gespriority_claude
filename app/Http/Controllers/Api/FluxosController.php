<?php

namespace App\Http\Controllers\Api;

use App\Exports\FluxosExport;
use App\Exports\RelatorioChamadosExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Relatorios\Fluxos\AgregadorFluxos;
use App\Relatorios\Fluxos\FonteFluxos;
use App\Relatorios\Fluxos\Passagem;
use App\Relatorios\Indicadores\ConsultaIndicadores;
use App\Relatorios\LinhaBase;
use App\Relatorios\ListaChamados;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `GET /relatorios/fluxos` — mapa de fluxos: passagens de chamados entre o
 * cliente e os grupos de solução no período, com abertos, recebidos,
 * encaminhados e resolvidos por grupo. Ver BACKEND_SPECS §3.4.9 "Mapa de fluxos".
 */
class FluxosController extends Controller
{
    private const METRICAS = [
        'abertos' => 'Abertos',
        'recebidos' => 'Recebidos',
        'encaminhados' => 'Encaminhados',
        'resolvidos' => 'Resolvidos',
    ];

    public function index(Request $request): JsonResponse|BinaryFileResponse
    {
        $validado = $request->validate(self::regras() + [
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ]);

        return $this->responder(ConsultaIndicadores::deDados($validado), $validado['formato'] ?? 'json', $request->user());
    }

    /**
     * Valida a visibilidade aqui (e não em `index()`) para nenhum chamador
     * esquecer: com `grupos`, a fonte filtra só pelos grupos pedidos.
     *
     * @throws ValidationException
     */
    public function responder(ConsultaIndicadores $c, string $formato, User $u): JsonResponse|BinaryFileResponse
    {
        $c->validarVisibilidade($u);

        $corpo = AgregadorFluxos::agregar($c, $u);

        if ($formato === 'xlsx') {
            Auditoria::registrar(
                'exportado',
                alteracoes: ['parametros' => $this->parametros($c)],
                entidadeType: 'relatorio',
                entidadeLabel: $corpo['titulo'],
            );

            return Excel::download(new FluxosExport($corpo, $u, now()), 'mapa-de-fluxos.xlsx');
        }

        return response()->json($corpo);
    }

    /**
     * Chamados por trás de um número (`no` + `metrica`) ou de uma seta
     * (`origem` + `destino`), a partir das mesmas linhas do agregador, para a
     * lista bater com o número. Abertos e resolvidos: um item por chamado
     * (evento mais recente); recebidos, encaminhados e seta: um item por passagem.
     */
    public function chamados(Request $request): JsonResponse|BinaryFileResponse
    {
        $validado = $request->validate(self::regras() + [
            'no' => ['sometimes', 'nullable', 'string', 'max:191'],
            'metrica' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(self::METRICAS))],
            'origem' => ['sometimes', 'nullable', 'string', 'max:191'],
            'destino' => ['sometimes', 'nullable', 'string', 'max:191'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ]);
        $c = ConsultaIndicadores::deDados($validado);
        $u = $request->user();
        $c->validarVisibilidade($u);

        $porNo = filled($validado['no'] ?? null) && filled($validado['metrica'] ?? null);
        $porSeta = filled($validado['origem'] ?? null) && filled($validado['destino'] ?? null);
        if ($porNo === $porSeta) {
            throw ValidationException::withMessages(['no' => 'Informe um nó e uma métrica, ou uma origem e um destino.']);
        }

        $corpo = AgregadorFluxos::agregar($c, $u);
        $rotulos = array_column($corpo['nos'], 'rotulo', 'chave');

        if ($porNo) {
            $no = collect($corpo['nos'])->firstWhere('chave', $validado['no']);
            if ($no === null) {
                throw ValidationException::withMessages(['no' => 'Nó inexistente neste mapa.']);
            }
            $metrica = $validado['metrica'];
            if ($no[$metrica] === null) {
                throw ValidationException::withMessages(['metrica' => 'Este número não existe para este nó.']);
            }
            $rotulo = "{$no['rotulo']} · ".self::METRICAS[$metrica];
            $selecionadas = $this->selecionarDoNo($c, $u, $no['chave'], $metrica);
        } else {
            $rotulo = ($rotulos[$validado['origem']] ?? $validado['origem']).' → '.($rotulos[$validado['destino']] ?? $validado['destino']);
            $selecionadas = $this->ordenar(FonteFluxos::passagens($c, $u)
                ->filter(fn (Passagem $p) => $p->origem === $validado['origem'] && $p->destino === $validado['destino'])
                ->map(fn (Passagem $p) => $p->base));
            $metrica = 'passagem';
        }
        $comDestino = ! in_array($metrica, ['abertos', 'resolvidos'], true);

        if (($validado['formato'] ?? 'json') === 'xlsx') {
            Auditoria::registrar(
                'exportado',
                alteracoes: ['parametros' => $this->parametros($c) + $request->only(['no', 'metrica', 'origem', 'destino'])],
                entidadeType: 'relatorio',
                entidadeLabel: "{$corpo['titulo']} — {$rotulo}",
            );

            return Excel::download(
                new RelatorioChamadosExport($corpo['titulo'], $rotulo, $this->itens($u, $selecionadas, $comDestino, $rotulos), $comDestino, $u, now()),
                'mapa-de-fluxos-chamados.xlsx'
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
            'filtro' => ['rotulo' => $rotulo],
            'data' => $this->itens($u, $pagina->getCollection(), $comDestino, $rotulos),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'last_page' => $pagina->lastPage(),
            ],
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * `ListaChamados::montar` sem vazar grupos invisíveis: o destino de uma
     * passagem sai do rótulo do nó (`Outros grupos` em vez do nome) e o grupo
     * atual do chamado só aparece se o usuário enxerga esse grupo.
     *
     * @param  Collection<int, LinhaBase>  $linhas
     * @param  array<string, string>  $rotulos  rótulo por chave de nó
     * @return list<array<string, mixed>>
     */
    private function itens(User $u, Collection $linhas, bool $comDestino, array $rotulos): array
    {
        $visiveis = $u->visibleGrupoSolucaoIds();
        $itens = ListaChamados::montar($u, $linhas, $comDestino);

        // `montar` devolve um item por linha, na mesma ordem.
        foreach ($linhas->values() as $i => $linha) {
            if ($comDestino) {
                // Passagens têm `chave` "{origem}>{destino}".
                $destino = substr((string) $linha->chave, strpos((string) $linha->chave, '>') + 1);
                $itens[$i]['destino'] = $rotulos[$destino] ?? $itens[$i]['destino'];
            }
            $grupo = $itens[$i]['grupo_chamado']['id'] ?? null;
            if ($visiveis !== null && $grupo !== null && ! in_array($grupo, $visiveis, true)) {
                $itens[$i]['grupo_chamado'] = null;
            }
        }

        return $itens;
    }

    /**
     * Linhas-base de um número do nó, mais recentes primeiro.
     *
     * @return Collection<int, LinhaBase>
     */
    private function selecionarDoNo(ConsultaIndicadores $c, User $u, string $chave, string $metrica): Collection
    {
        return match ($metrica) {
            'abertos' => $this->ordenar(FonteFluxos::abertos($c, $u)->filter(fn (LinhaBase $l) => (string) $l->chave === $chave))->unique('incidenteId')->values(),
            'resolvidos' => $this->ordenar(FonteFluxos::resolvidos($c, $u)->filter(fn (LinhaBase $l) => (string) $l->chave === $chave))->unique('incidenteId')->values(),
            'recebidos' => $this->ordenar(FonteFluxos::passagens($c, $u)->filter(fn (Passagem $p) => $p->destino === $chave)->map(fn (Passagem $p) => $p->base)),
            'encaminhados' => $this->ordenar(FonteFluxos::passagens($c, $u)->filter(fn (Passagem $p) => $p->origem === $chave)->map(fn (Passagem $p) => $p->base)),
        };
    }

    /**
     * @param  Collection<int, LinhaBase>  $linhas
     * @return Collection<int, LinhaBase>
     */
    private function ordenar(Collection $linhas): Collection
    {
        return $linhas->sortByDesc(fn (LinhaBase $l) => [$l->data->getTimestamp(), $l->eventoId ?? 0, $l->incidenteId])->values();
    }

    /**
     * Parâmetros da consulta como executada, para o log de auditoria.
     *
     * @return array<string, mixed>
     */
    private function parametros(ConsultaIndicadores $c): array
    {
        return [
            'grupos' => $c->grupos,
            'periodo' => $c->atalho,
            'data_inicio' => $c->inicio,
            'data_fim' => $c->fim,
        ];
    }

    /**
     * Período e grupos dos Indicadores, sem quebra nem detalhamento por integrante.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function regras(): array
    {
        return array_diff_key(ConsultaIndicadores::regras(), array_flip(['quebra', 'por_integrante']));
    }
}
