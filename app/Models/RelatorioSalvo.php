<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Database\Factories\RelatorioSalvoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'nome', 'filtros', 'agrupar_por', 'contar', 'quebra'])]
class RelatorioSalvo extends Model
{
    /** @use HasFactory<RelatorioSalvoFactory> */
    use Auditavel, HasFactory;

    // Pluralização automática do Eloquent daria 'relatorio_salvos' (só o
    // último termo pluralizado); a tabela é 'relatorios_salvos'.
    protected $table = 'relatorios_salvos';

    /** `contar` do relatório "Indicadores por grupo"; `agrupar_por` é `grupo` ou `integrante`. */
    public const CONTAR_INDICADORES = 'indicadores';

    public const AGRUPAMENTOS = [
        'status_sla', 'responsavel', 'aberto_por', 'resolvido_por', 'fechado_por', 'concluido_por',
        'encaminhado_por', 'encaminhado_para_grupo', 'encaminhado_para_responsavel',
        'grupo_solucao', 'categoria', 'subcategoria', 'item',
    ];

    /**
     * Agrupamentos legados que já têm par exato no caminho novo, para o
     * relatório antigo abrir com o mesmo significado, agora com totais, quebra
     * e lista de chamados. `categoria`, `responsavel`, `status_sla`... ficam de
     * fora: o legado os restringe a resolvido/fechado, o novo não, então
     * os números mudariam.
     */
    public const EQUIVALENTES_LEGADO = [
        'resolvido_por' => ['resolvidos', 'analista'],
        'fechado_por' => ['fechados', 'analista'],
        'concluido_por' => ['concluidos', 'analista'],
        'encaminhado_por' => ['encaminhamentos', 'quem_encaminhou'],
        'encaminhado_para_grupo' => ['encaminhamentos', 'para_grupo'],
        'encaminhado_para_responsavel' => ['encaminhamentos', 'para_responsavel'],
        'aberto_por' => ['abertos', 'aberto_por'],
    ];

    /**
     * Consulta do caminho novo equivalente a este relatório, ou null quando
     * ele só roda no legado. `aberto_por` com datas não é equivalente: o legado
     * filtra por `concluido_em`, o novo por `created_at`. Período com uma
     * ponta só também não: o caminho novo exige as duas, e descartar uma
     * mudaria o recorte.
     *
     * @return array{contar: string, agrupar_por: string, quebra: string}|null
     */
    public function consultaEquivalente(): ?array
    {
        $par = self::EQUIVALENTES_LEGADO[$this->agrupar_por] ?? null;
        if ($par === null) {
            return null;
        }

        $filtros = $this->filtros ?? [];
        $temInicio = isset($filtros['data_inicio']);
        $temFim = isset($filtros['data_fim']);

        // Datas fora de Y-m-d (legado aceitava ISO) ficam no caminho legado.
        foreach (['data_inicio', 'data_fim'] as $campo) {
            if (isset($filtros[$campo]) && ! self::dataYmdValida($filtros[$campo])) {
                return null;
            }
        }

        if ($temInicio !== $temFim || ($this->agrupar_por === 'aberto_por' && $temInicio)) {
            return null;
        }

        return ['contar' => $par[0], 'agrupar_por' => $par[1], 'quebra' => 'nenhuma'];
    }

    private static function dataYmdValida(mixed $v): bool
    {
        if (! is_string($v)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * Consulta efetiva do caminho novo (armazenada ou equivalente do legado),
     * ou null quando o relatório é legado.
     *
     * @return array{contar: string, agrupar_por: string, quebra: string}|null
     */
    public function consultaNova(): ?array
    {
        if ($this->contar !== null) {
            return ['contar' => $this->contar, 'agrupar_por' => $this->agrupar_por, 'quebra' => $this->quebra ?? 'nenhuma'];
        }

        return $this->consultaEquivalente();
    }

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
