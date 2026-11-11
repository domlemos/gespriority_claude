<?php

namespace App\Http\Controllers\Api;

use App\Exports\RelatorioTabelaExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Relatorios\AgregadorRelatorio;
use App\Relatorios\ConsultaRelatorio;
use App\Relatorios\TituloRelatorio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Caminho novo de `GET /relatorios/incidentes` — ativado por `contar`
 * (`RelatorioController::index()` delega para cá). O legado continua
 * intacto quando `contar` não vem, para não quebrar relatórios salvos nem
 * integrações (spec 6.5).
 */
class RelatorioConsultaController extends Controller
{
    /** Agrupamentos por pessoa: com `grupo_analista_id`, os membros ociosos entram com 0. */
    private const AGRUPAMENTOS_POR_USUARIO = ['analista', 'responsavel', 'quem_encaminhou'];

    public function index(Request $request): JsonResponse|BinaryFileResponse
    {
        // Uma única passada de validação: `formato` entra junto com os demais
        // campos para o 422 trazer todos os erros de uma vez.
        $validado = $request->validate(ConsultaRelatorio::regras() + [
            'formato' => ['sometimes', 'string', Rule::in(['json', 'xlsx'])],
        ]);

        return $this->responder(ConsultaRelatorio::deDados($validado), $validado['formato'] ?? 'json', $request->user());
    }

    public function validado(Request $request): ConsultaRelatorio
    {
        return ConsultaRelatorio::deDados($request->validate(ConsultaRelatorio::regras()));
    }

    public function responder(ConsultaRelatorio $c, string $formato, User $u): JsonResponse|BinaryFileResponse
    {
        $fonte = $c->fonte();
        $resultado = AgregadorRelatorio::agregar($c, $fonte->linhas($c, $u), $fonte, $this->zerados($c))
            ->comTitulo(TituloRelatorio::montar($c));

        if ($formato === 'xlsx') {
            return Excel::download(
                new RelatorioTabelaExport($resultado->titulo, $resultado, $u, now()),
                "relatorio-{$c->contar}-{$c->agruparPor}.xlsx"
            );
        }

        // `percentual` sai sempre com casa decimal (100.0, não 100): o tipo não muda conforme o valor.
        return response()->json($resultado->toArray(), options: JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * "Grupo do analista" mede um grupo de pessoas: quem está nele e não
     * apareceu é justamente o dado que o gestor procura (analista ocioso).
     * Só usuários ativos — soft-deleted não entram.
     *
     * @return Collection<int, string>|null
     */
    private function zerados(ConsultaRelatorio $c): ?Collection
    {
        $grupoId = $c->filtros['grupo_analista_id'] ?? null;

        if ($grupoId === null || ! in_array($c->agruparPor, self::AGRUPAMENTOS_POR_USUARIO, true)) {
            return null;
        }

        return User::query()->where('grupo_solucao_id', $grupoId)->pluck('name', 'id');
    }
}
