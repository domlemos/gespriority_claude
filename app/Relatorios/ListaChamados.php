<?php

namespace App\Relatorios;

use App\Models\GrupoSolucao;
use App\Models\Incidente;
use App\Models\IncidenteEvento;
use App\Models\User;
use App\Support\PeriodoRelatorio;
use Illuminate\Support\Collection;

/**
 * A lista de chamados por trás de uma linha (e opcionalmente uma coluna) do
 * relatório. Parte das MESMAS `LinhaBase` que a consulta agrega, para o
 * número da célula e o tamanho da lista nunca divergirem: um item por
 * chamado (com data/autor do evento mais recente no escopo) ou, em
 * `encaminhamentos`, um item por evento — a mesma unidade que a célula conta.
 */
final class ListaChamados
{
    /**
     * Linhas-base da chave/coluna pedidas, mais recentes primeiro.
     *
     * @return Collection<int, LinhaBase>
     */
    public static function selecionar(ConsultaRelatorio $c, User $u, string $chave, ?string $coluna): Collection
    {
        $fonte = $c->fonte();

        // Chaves comparadas como string: a fonte devolve ids como int e a query string traz texto.
        $linhas = $fonte->linhas($c, $u)->filter(fn (LinhaBase $l) => (string) ($l->chave ?? RotulosRelatorio::CHAVE_VAZIA) === $chave
            && ($coluna === null || PeriodoRelatorio::colunaDe($l->data, $c->quebra) === $coluna));

        $ordenadas = $linhas->sortByDesc(fn (LinhaBase $l) => [$l->data->getTimestamp(), $l->eventoId ?? 0, $l->incidenteId])->values();

        // Chamado repetido (várias conclusões na linha): fica o evento mais recente.
        return $fonte->contaEventos() ? $ordenadas : $ordenadas->unique('incidenteId')->values();
    }

    /**
     * Completa as linhas com dados do chamado em uma query por tabela.
     * `acessivel` usa a regra de hoje (`visiveisPara`): a linha pode aparecer
     * por snapshot do evento num chamado que o usuário já não consegue abrir.
     * `comDestino`: inclui o grupo/responsável de destino (relatório de encaminhamentos).
     *
     * @param  Collection<int, LinhaBase>  $linhas
     * @return list<array<string, mixed>>
     */
    public static function montar(User $u, Collection $linhas, bool $comDestino): array
    {
        if ($linhas->isEmpty()) {
            return [];
        }

        $ids = $linhas->pluck('incidenteId')->unique()->all();
        $incidentes = Incidente::query()->with('grupoSolucao:id,nome')->whereIn('id', $ids)->get()->keyBy('id');
        $acessiveis = array_flip(Incidente::query()->visiveisPara($u)->whereIn('id', $ids)->pluck('id')->all());
        $autores = RotulosRelatorio::usuarios($linhas->pluck('autorId')->filter()->unique()->all());
        $destinos = $comDestino ? self::destinos($linhas) : [];

        return $linhas->map(function (LinhaBase $l) use ($incidentes, $acessiveis, $autores, $destinos) {
            $incidente = $incidentes[$l->incidenteId];

            return [
                'incidente_id' => $l->incidenteId,
                'titulo' => $incidente->titulo,
                'status' => $incidente->status,
                'data' => $l->data->toIso8601String(),
                'autor' => $l->autorId === null ? null : ['id' => $l->autorId, 'name' => $autores[$l->autorId] ?? "#{$l->autorId}"],
                'grupo_chamado' => $incidente->grupoSolucao === null ? null : ['id' => $incidente->grupoSolucao->id, 'nome' => $incidente->grupoSolucao->nome],
                'destino' => $destinos[$l->eventoId] ?? null,
                'acessivel' => isset($acessiveis[$l->incidenteId]),
            ];
        })->all();
    }

    /**
     * Nome do grupo/responsável de destino de cada evento de encaminhamento.
     *
     * @return array<int, string>
     */
    private static function destinos(Collection $linhas): array
    {
        $eventos = IncidenteEvento::query()->whereIn('id', $linhas->pluck('eventoId')->filter()->all())->get(['id', 'alvo_type', 'alvo_id']);

        $grupos = RotulosRelatorio::grupos($eventos->where('alvo_type', GrupoSolucao::class)->pluck('alvo_id')->all());
        $usuarios = RotulosRelatorio::usuarios($eventos->where('alvo_type', User::class)->pluck('alvo_id')->all());

        return $eventos->mapWithKeys(fn (IncidenteEvento $e) => [
            $e->id => $e->alvo_type === GrupoSolucao::class ? ($grupos[$e->alvo_id] ?? null) : ($usuarios[$e->alvo_id] ?? null),
        ])->all();
    }
}
